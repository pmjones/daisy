<?php
declare(strict_types=1);

namespace pmjones\Daisy;

use ReflectionClass;
use RuntimeException;
use Throwable;

class DaisyCommands
{
    public function __construct(
        protected DaisyCli $cli = new DaisyCli(),
    ) {
    }

    /**
     * @param string[] $args
     */
    public function __invoke(array $args, bool $verbose = false) : int
    {
        if (! $args) {
            return $this->help();
        }

        try {
            $this->cli->verbose = $verbose;
            $method = array_shift($args);

            if (! $this->isCommand($method)) {
                return $this->cli->error(
                    "Daisy command '{$method}' does not exist."
                );
            }

            $exitCode = $this->$method(...$args);
        } catch (Throwable $e)  {
            $exitCode = $this->cli->error($e->getMessage(), $e->getCode());
        }

        /** @var int $exitCode */
        return $exitCode;
    }

    public function start(?string $name = null) : int
    {
        if ($name === null) {
            return $this->cli->error(
                'Please give a name for the daisy chain you want to start.',
            );
        }

        $root = $this->getCurrentBranch();

        if (! $root) {
            return $this->cli->error(
                'Not on a branch to start from.',
            );
        }

        if (Daisy::isValid($root)) {
            return $this->cli->error(
                'Current branch is already part of a daisy chain: {$root)',
            );
        }

        $this->cli->info("Starting a new daisy chain.");
        $start = Daisy::fromStart($name);

        $this->cli->runOrThrow(
            "git checkout -b {$start->branch}",
            "Could not start daisy chain branch {$start->branch}.",
        );

        $this->cli->runOrThrow(
            "git commit --allow-empty --message=':daisy-chain-root {$root}'",
            "Could not commit to daisy chain branch {$start->branch}.",
        );

        $this->cli->info("Created daisy chain {$start->branch} from {$root}.");

        $this->cli->runOrThrow(
            "git switch {$root}",
            "Could not switch back to daisy chain root branch {$root}.",
        );

        $first = $start->withNextNumber();

        $this->cli->runOrThrow(
            "git checkout -b {$first->branch}",
            "Could not add daisy chain branch {$first->branch}.",
        );

        $this->cli->runOrThrow(
            "git commit --allow-empty --message=':daisy-chain-add {$first->branch}'",
            "Could not commit to daisy chain branch {$first->branch}",
        );

        return $this->status();
    }

    public function add() : int
    {
        $this->cli->info("Adding a new branch to the daisy chain.");
        $daisy = $this->getDaisy();
        $chain = $this->getChain();
        $last = array_pop($chain);

        if ($daisy->branch !== $last) {
            return $this->cli->error(
                'Not on the last branch in the daisy chain.',
            );
        }

        $add = $daisy->withNextNumber();

        $this->cli->runOrThrow(
            "git checkout -b {$add->branch}",
            "Could not add daisy chain branch {$add->branch}",
        );

        $message = ":daisy-chain-add {$add->branch}";

        $this->cli->runOrThrow(
            "git commit --allow-empty --message={$message}",
            "Could not commit to daisy chain branch {$add->branch}",
        );

        return $this->status();
    }

    public function first() : int
    {
        $daisy = $this->getDaisy();
        $chain = $this->getChain();

        /** @var string|false */
        $first = reset($chain);

        if ($daisy->branch === $first) {
            return $this->cli->error("Already at first branch.");
        }

        $this->cli->runOrThrow(
            "git switch {$first}",
            "Could not switch to daisy chain branch {$first}.",
        );

        return $this->branch();
    }

    public function prev(?string $steps = '1') : int
    {
        $daisy = $this->getDaisy();
        $chain = $this->getChain();
        $first = reset($chain);

        if ($daisy->branch === $first) {
            return $this->cli->error("Already at first branch.");
        }

        $key = ((int) array_search($daisy->branch, $chain)) - (int) $steps;

        /** @var string|false $prev */
        $prev = $chain[$key] ?? reset($chain);

        $this->cli->runOrThrow(
            "git switch {$prev}",
            "Could not switch to daisy chain branch {$prev}.",
        );

        return $this->branch();
    }

    public function next(?string $steps = '1') : int
    {
        $daisy = $this->getDaisy();
        $chain = $this->getChain();
        $last = end($chain);

        if ($daisy->branch === $last) {
            return $this->cli->error("Already at last branch.");
        }

        $key = ((int) array_search($daisy->branch, $chain)) + (int) $steps;

        /** @var string|false $next */
        $next = $chain[$key] ?? end($chain);

        $this->cli->runOrThrow(
            "git switch {$next}",
            "Could not switch to daisy chain branch {$next}.",
        );

        return $this->branch();
    }

    public function last() : int
    {
        $daisy = $this->getDaisy();
        $chain = $this->getChain();

        /** @var string|false $last */
        $last = end($chain);

        if ($daisy->branch === $last) {
            return $this->cli->error("Already at last branch.");
        }

        $this->cli->runOrThrow(
            "git switch {$last}",
            "Could not switch to daisy chain branch {$last}.",
        );

        return $this->branch();
    }

    public function goto(?string $where = null) : int
    {
        $daisy = $this->getDaisy();
        $where = strtolower((string) $where);

        if (! preg_match('/^(root|start|\d+)$/', $where)) {
            return $this->cli->error("Please specify 'root', 'start', or a daisy chain number.");
        }

        $branch = match(strtolower($where)) {
            'root' => $this->getRootBranch($daisy),
            'start' => $daisy->getStartBranch(),
            default => $daisy->withNumber((int) $where)->branch,
        };

        $this->cli->runOrThrow(
            "git switch {$branch}",
            "Could not switch to root branch {$branch}",
        );

        return $this->cli->info($this->getCurrentBranch());
    }

    public function branch() : int
    {
        $daisy = $this->getDaisy();
        return $this->cli->info($daisy->branch);
    }

    public function chain() : int
    {
        $chain = $this->getChain();
        return $this->cli->info(implode(PHP_EOL, $chain));
    }

    public function send() : int
    {
        $daisy = $this->getDaisy();

        return $this->hasUpstream()
            ? $this->forcePushWithLease($daisy->branch)
            : $this->setUpstreamAndPush($daisy->branch);
    }

    public function diff() : int
    {
        $daisy = $this->getDaisy();
        $before = $this->getBranchBefore($daisy);
        $result = $this->cli->run("git diff --minimal {$before}");
        return $this->cli->info(implode(PHP_EOL, $result->output) . PHP_EOL);
    }

    public function sync() : int
    {
        $daisy = $this->getDaisy();

        if ($this->hasUpstream()) {
            $this->cli->runOrThrow(
                "git pull",
                "Could not pull remote changes before rebasing.",
            );
        }

        $before = $this->getBranchBefore($daisy);

        $this->cli->runOrThrow(
            "git rebase --update-refs --allow-empty {$before}",
            "Could not rebase using {$before}",
        );

        return $this->cli->info("Now in sync with with {$before}");
    }

    public function open() : int
    {
        $result = $this->cli->runOrThrow(
            'git remote get-url origin',
            'Could not get origin URL.',
        );

        $origin = $result->lastLine;

        if (! preg_match('#^.+:(.+)/(.+)$#', $origin, $matches)) {
            return $this->cli->error("Could not parse origin URL: {$origin}");
        }

        $owner = $matches[1];
        $repo = $matches[2];

        if (str_ends_with($repo, '.git')) {
            $repo = substr($repo, 0, -4);
        }

        $daisy = $this->getDaisy();
        $before = $this->getBranchBefore($daisy);
        $url = "https://github.com/{$owner}/{$repo}/compare/{$before}...{$daisy->branch}";
        return $this->cli->run("open {$url}")->exitCode;
    }

    public function drop(?string $where) : int
    {
        return $this->deleteBranch('drop', (string) $where);
    }

    public function kill(?string $where) : int
    {
        return $this->deleteBranch('kill', (string) $where);
    }

    protected function isCommand(string $method) : bool
    {
        if (! method_exists($this, $method)) {
            return false;
        }

        $rc = new ReflectionClass($this);
        $rm = $rc->getMethod($method);
        return $rm->isPublic();
    }

    protected function deleteBranch(string $type, string $where) : int
    {
        $daisy = $this->getDaisy();
        $where = strtolower($where);

        if (! preg_match('/^(first|next|prev|last|\d+)$/', $where)) {
            return $this->cli->error(
                "Please specify 'first', 'next', 'prev', 'last', or a daisy chain number."
            );
        }

        $chain = $this->getChain();

        /** @var int $key */
        $key = match ($where) {
            'first' => reset($chain),
            'prev' => ((int) array_search($daisy->branch, $chain)) - 1,
            'next' => ((int) array_search($daisy->branch, $chain)) + 1,
            'last' => end($chain),
            default => $daisy->getNumberedBranch((int) $where),
        };

        $branch = $chain[$key] ?? null;

        if (! $branch) {
            return $this->cli->error("Unknown daisy chain location: {$where}");
        }

        if ($branch === $daisy->branch) {
            return $this->cli->error("Cannot {$type} current branch.");
        }

        $option = match ($type) {
            'kill' => '-D',
            default => '-d',
        };

        $this->cli->runOrThrow(
            "git branch {$option} {$branch}",
            "Could not delete branch {$branch}.",
        );

        return $this->cli->info("Deleted branch {$branch}.");
    }

    protected function hasUpstream() : bool
    {
        $result = $this->cli->run('git rev-parse --abbrev-ref @{u}');
        return ! $result->exitCode;
    }

    protected function setUpstreamAndPush(string $branch) : int
    {
        $this->cli->info("Setting upstream and pushing.");

        $this->cli->runOrThrow(
            "git push -u origin {$branch}",
            "Could set upstream and push daisy chain branch {$branch}.",
        );

        return $this->cli->info(
            "Set upstream and pushed daisy chain branch {$branch}."
        );
    }

    protected function forcePushWithLease(string $branch) : int
    {
        $this->cli->info("Force-pushing.");

        $this->cli->runOrThrow(
            "git push --force-with-lease",
            "Could not force-push daisy chain branch {$branch}.",
        );

        return $this->cli->info("Force-pushed daisy chain branch {$branch}.");
    }

    protected function getCurrentBranch() : string
    {
        $result = $this->cli->runOrThrow(
            'git branch --show-current',
            'Could not get current branch.',
        );

        return $result->lastLine;
    }

    protected function getDaisy() : Daisy
    {
        $branch = $this->getCurrentBranch();
        $daisy = Daisy::fromBranch($branch);

        if (! $daisy) {
            throw new RuntimeException(
                "This branch does not look like part of a daisy chain: {$branch}"
            );
        }

        return $daisy;
    }

    protected function getBranchBefore(Daisy $daisy) : string
    {
        $chain = $this->getChain();
        $key = ((int) array_search($daisy->branch, $chain)) - 1;
        return $chain[$key] ?? $this->getRootBranch($daisy);
    }

    protected function getRootBranch(Daisy $daisy) : string
    {
        $start = $daisy->getStartBranch();

        $result = $this->cli->runOrThrow(
            "git log --no-decorate --oneline -1 {$start}",
            "Could not get commit message from start branch {$start}.",
        );

        $found = preg_match('/:daisy-chain-root (.*)$/', trim($result->lastLine), $matches);

        if (! $found) {
            throw new RuntimeException(
                "Could not find ':daisy-chain-root' in first line of commit message on start branch {$start}.",
                1,
            );
        }

        $root = trim($matches[1]);

        if (! $root) {
            throw new RuntimeException(
                "Could not find value for ':daisy-chain-root' on start branch {$start}.",
                1,
            );
        }

        return $root;
    }

    /**
     * @return string[]
     */
    protected function getChain() : array
    {
        $chain = [];
        $daisy = $this->getDaisy();
        $start = $daisy->getStartBranch();

        $result = $this->cli->runOrThrow(
            'git branch -a --format="%(refname:short)"',
            'Could not get list of branches.',
        );

        foreach ($result->output as $branch) {
            if (str_starts_with($branch, 'remotes/origin/')) {
                $branch  = substr($branch, strlen('remotes/origin/'));
            }

            if (
                str_starts_with($branch, $start)
                && Daisy::isNumbered($branch)
            ) {
                $chain[] = $branch;
            }
        }

        $chain = array_unique($chain);
        natsort($chain);
        return array_values($chain);
    }

    protected function status() : int
    {
        $result = $this->cli->run('git status');
        echo implode(PHP_EOL, $result->output) . PHP_EOL;
        return $result->exitCode;
    }

    public function help() : int
    {
        return $this->cli->info(<<<'HELP'
            Daisy is a tool for managing daisy chains of git branches.

            Usage:
                daisy <command>

            Creation Commands:

                start <name>
                    Starts a new daisy chain of branches with the <name>
                    prefix by creating a non-numbered "start" branch with
                    an empty commit with a message indicating the "root"
                    branch of the daisy chain. DO NOT work on this branch.
                    It is for tracking the "root" branch only.

                add
                    Adds a numbered branch to the end of the daisy chain.

            Navigation Commands:

                first
                    Switches to the first numbered branch in the daisy chain.

                prev [<steps>]
                    Switches to the previous numbered branch in the daisy chain,
                    or any number of <steps> previous.

                next [<steps>]
                    Switches to the next numbered branch in the daisy chain, or
                    any number of <steps> next.

                last
                    Switches to the last numbered branch in the daisy chain.

                goto (root|start|<number>)
                    Switches to the root branch, start branch, or a numbered
                    daisy chain branch.

            Info Commands:

                branch
                    Shows the current branch in the daisy chain.

                chain
                    Shows the list of branches in the daisy chain.

                diff
                    Shows the diff between the current branch in the daisy
                    and the previous one. If on the first branch in the
                    daisy chain, shows the diff between the current branch
                    and the root branch.

            Deletion Commands:

                drop (first|prev|next|last|<number>)

                    Deletes the specified branch from the daisy chain
                    using `git branch -d`.

                kill (first|prev|next|last|<number>)

                    Deletes the specified branch from the daisy chain
                    using `git branch -D`.

            Management Commands:

                open
                    Opens a comparison request for the current branch.

                send
                    Force-pushes the current numbered branch in the daisy chain
                    to origin, setting the upstream if not already set.

                sync
                    Pulls from the remote origin (if there is one), then
                    rebases the current numbered branch in the daisy chain on
                    the previous one. If on the first numbered branch in the
                    daisy chain, rebases on the root branch.

            HELP,
        );
    }
}

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
                "Current branch {$root} is already part of a daisy chain.",
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
            "Could not commit to daisy chain branch {$first->branch}.",
        );

        return $this->status();
    }

    public function add() : int
    {
        $this->cli->info("Adding a new branch to the daisy chain.");
        $daisy = $this->getDaisy();

        if ($daisy->suffix) {
            return $this->cli->error(
                "Cannot add a daisy chain branch from a temporary branch.",
            );
        }

        $chain = $this->getChainWithoutSuffixed();
        $add = $daisy->withNextNumber();

        if (in_array($add->branch, $chain)) {
            return $this->cli->error(
                "Cannot add daisy chain branch {$add->branch} because it already exists.",
            );
        }

        $this->cli->runOrThrow(
            "git checkout -b {$add->branch}",
            "Could not add daisy chain branch {$add->branch}.",
        );

        $message = ":daisy-chain-add {$add->branch}";

        $this->cli->runOrThrow(
            "git commit --allow-empty --message='{$message}'",
            "Could not commit to added daisy chain branch {$add->branch}.",
        );

        return $this->status();
    }

    public function first() : int
    {
        $daisy = $this->getDaisy()->withoutSuffix();
        $chain = $this->getChainWithoutSuffixed();

        if (! $chain) {
            return $this->cli->error(
                "There are no branches in this daisy chain."
            );
        }

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

    public function prev(?string $steps = null) : int
    {
        $daisy = $this->getDaisy();

        if ($daisy->suffix) {
            $prev = $daisy->withoutSuffix()->branch;

            $this->cli->runOrThrow(
                "git switch {$prev}",
                "Could not switch to daisy chain branch {$prev}.",
            );

            return $this->branch();
        }

        $steps ??= '1';

        if (! ctype_digit($steps)) {
            return $this->cli->error(
                "Specify a whole number of steps, or nothing at all.",
            );
        }

        $steps = (int) $steps;
        $chain = $this->getChainWithoutSuffixed();

        if (! $chain) {
            return $this->cli->error(
                "There are no branches in this daisy chain."
            );
        }

        $first = reset($chain);

        if ($daisy->branch === $first) {
            return $this->cli->error("Already at first branch.");
        }

        $key = (int) array_search($daisy->branch, $chain) - $steps;
        $prev = $chain[$key] ?? reset($chain);

        $this->cli->runOrThrow(
            "git switch {$prev}",
            "Could not switch to daisy chain branch {$prev}.",
        );

        return $this->branch();
    }

    public function next(?string $steps = null) : int
    {
        $daisy = $this->getDaisy()->withoutSuffix();
        $steps ??= '1';

        if (! ctype_digit($steps)) {
            return $this->cli->error(
                "Specify a whole number of steps, or nothing at all.",
            );
        }

        $steps = (int) $steps;
        $chain = $this->getChainWithoutSuffixed();

        if (! $chain) {
            return $this->cli->error(
                "There are no branches in this daisy chain."
            );
        }

        $last = end($chain);

        if ($daisy->branch === $last) {
            return $this->cli->error("Already at last branch.");
        }

        if ($daisy->number === null) {
            // on the "start" branch.
            $key = $steps - 1;
        } else {
            $key = (int) array_search($daisy->branch, $chain) + $steps;
        }

        $next = $chain[$key] ?? end($chain);

        $this->cli->runOrThrow(
            "git switch {$next}",
            "Could not switch to daisy chain branch {$next}.",
        );

        return $this->branch();
    }

    public function last() : int
    {
        $daisy = $this->getDaisy()->withoutSuffix();
        $chain = $this->getChainWithoutSuffixed();

        if (! $chain) {
            return $this->cli->error(
                "There are no branches in this daisy chain."
            );
        }

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
        if ($where === null) {
            return $this->cli->error("Please specify 'root', 'start', or a daisy chain branch.");
        }

        $daisy = $this->getDaisy();
        $branch = $this->getGotoBranch($daisy, $where);

        $this->cli->runOrThrow(
            "git switch {$branch}",
            "Could not switch to branch {$branch}.",
        );

        return $this->cli->info($this->getCurrentBranch());
    }

    protected function getGotoBranch(Daisy $daisy, string $where) : string
    {
        if (strtolower($where) === 'root') {
            return $this->getRootBranch($daisy);
        }

        if (strtolower($where) === 'start') {
            return $daisy->getStartBranch();
        }

        $branch = $daisy->getStartBranch() . $where;
        $this->assertKnownBranch($branch);
        return $branch;
    }

    public function branch() : int
    {
        $daisy = $this->getDaisy();
        return $this->cli->info($daisy->branch);
    }

    public function chain() : int
    {
        $chain = $this->getChainWithSuffixed();
        return $this->cli->info($chain);
    }

    public function send() : int
    {
        return $this->hasUpstream()
            ? $this->forcePushWithLease()
            : $this->setUpstreamAndPush();
    }

    public function diff(?string $plain = null) : int
    {
        $daisy = $this->getDaisy();
        $plain = strtolower($plain ?? '');

        if ($plain !== '' && $plain !== 'plain') {
            return $this->cli->error(
                "Specify 'plain' or nothing at all."
            );
        }

        $color = $plain ? "never" : "always";
        $prev = $this->getPrevBranch($daisy);

        $result = $this->cli->run(
            "git diff --color={$color} {$prev}"
        );

        return $this->cli->info($result->output);
    }

    public function sync() : int
    {
        $this->assertDaisy();

        return $this->hasUpstream()
            ? $this->pullThenRebase()
            : $this->rebase();
    }

    protected function pullThenRebase() : int
    {
        $result = $this->cli->run("git pull");

        if (! $result->exitCode) {
            return $this->rebase();
        }

        return $this->cli->error(
            <<<MESSAGE

            Could not pull cleanly from remote. Issue the following ...

                git pull

            ... to pull manually instead.

            MESSAGE
        );
    }

    protected function rebase() : int
    {
        $daisy = $this->getDaisy();
        $prev = $this->getPrevBranch($daisy);

        $result = $this->cli->run(
            "git rebase --update-refs --allow-empty {$prev}",
        );

        if (! $result->exitCode) {
            return $this->cli->info("Now in sync with with {$prev}.");
        }

        $this->cli->run("git rebase --abort");

        return $this->cli->error(
            <<<MESSAGE

            Could not rebase cleanly on {$prev}. Issue the following ...

                git rebase -i --update-refs --allow-empty {$prev}

            ... to rebase interactively instead.

            MESSAGE
        );
    }

    public function open() : int
    {
        $daisy = $this->getDaisy();

        $result = $this->cli->runOrThrow(
            'git remote get-url origin',
            'Could not get origin URL.',
        );

        $origin = $result->lastLine;

        if (! preg_match('#^.+:(.+)/(.+)$#', $origin, $matches)) {
            return $this->cli->error("Could not parse origin URL: {$origin}.");
        }

        $owner = $matches[1];
        $repo = $matches[2];

        if (str_ends_with($repo, '.git')) {
            $repo = substr($repo, 0, -4);
        }

        $prev = $this->getPrevBranch($daisy);
        $url = "https://github.com/{$owner}/{$repo}/compare/{$prev}...{$daisy->branch}";
        return $this->cli->run("open {$url}")->exitCode;
    }

    public function drop(?string $where) : int
    {
        return $this->deleteBranch('drop', '-d', (string) $where);
    }

    public function kill(?string $where) : int
    {
        return $this->deleteBranch('kill', '-D', (string) $where);
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

    protected function deleteBranch(string $type, string $option, ?string $where) : int
    {
        $daisy = $this->getDaisy();

        if ($where === null) {
            return $this->cli->error("Please specify a branch to {$type}.");
        }

        $branch = $daisy->getStartBranch() . $where;
        $this->assertKnownBranch($branch);

        if ($branch === $daisy->branch) {
            return $this->cli->error("Cannot {$type} current branch.");
        }

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

    protected function setUpstreamAndPush() : int
    {
        $daisy = $this->getDaisy();
        $this->cli->info("Setting upstream and pushing.");

        $this->cli->runOrThrow(
            "git push -u origin {$daisy->branch}",
            "Could not set upstream and push daisy chain branch {$daisy->branch}.",
        );

        return $this->cli->info(
            "Set upstream and pushed daisy chain branch {$daisy->branch}."
        );
    }

    protected function forcePushWithLease() : int
    {
        $daisy = $this->getDaisy();
        $this->cli->info("Force-pushing.");

        $this->cli->runOrThrow(
            "git push --force-with-lease",
            "Could not force-push daisy chain branch {$daisy->branch}.",
        );

        return $this->cli->info(
            "Force-pushed daisy chain branch {$daisy->branch}."
        );
    }

    protected function getCurrentBranch() : string
    {
        $result = $this->cli->runOrThrow(
            'git branch --show-current',
            'Could not get current branch.',
        );

        return $result->lastLine;
    }

    protected function assertDaisy() : void
    {
        $this->getDaisy();
    }

    protected function getDaisy() : Daisy
    {
        $branch = $this->getCurrentBranch();
        $daisy = Daisy::fromBranch($branch);

        if (! $daisy) {
            throw new RuntimeException(
                "This branch does not look like part of a daisy chain: {$branch}."
            );
        }

        return $daisy;
    }

    protected function getPrevBranch(Daisy $daisy) : string
    {
        if ($daisy->suffix) {
            return $daisy->withoutSuffix()->branch;
        }

        $chain = $this->getChainWithoutSuffixed();
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
    protected function getChainWithoutSuffixed() : array
    {
        $chain = [];

        foreach ($this->getChainWithSuffixed() as $branch) {
            if (! Daisy::isSuffixed($branch)) {
                $chain[] = $branch;
            }
        }

        return $chain;
    }

    /**
     * @return string[]
     */
    protected function getChainWithSuffixed() : array
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
        return $this->cli->info($result->output);
    }

    public function temp(?string $suffix, string ...$extras) : int
    {
        if (! $suffix) {
            return $this->cli->error(
                "Please give a suffix for the temporary branch.",
            );
        }

        if ($extras) {
            return $this->cli->error(
                "Cannot create a temporary branch with spaces in the suffix name.",
            );
        }

        $this->cli->info("Creating a temporary branch from the daisy chain.");
        $daisy = $this->getDaisy();

        if ($daisy->suffix) {
            return $this->cli->error(
                "Already on a temporary branch.",
            );
        }

        $temp = $daisy->withSuffix($suffix);

        if (! $temp) {
            return $this->cli->error(
                "Cannot create a temporary branch with suffix '{$suffix}'.",
            );
        }

        $this->cli->runOrThrow(
            "git checkout -b {$temp->branch}",
            "Could not create temporary branch {$temp->branch}.",
        );

        $message = ":daisy-chain-temp {$temp->branch}";

        $this->cli->runOrThrow(
            "git commit --allow-empty --message='{$message}'",
            "Could not commit to temp daisy chain branch {$temp->branch}.",
        );

        return $this->status();
    }

    protected function assertKnownBranch(string $branch) : void
    {
        $chain = $this->getChainWithSuffixed();

        if (! in_array($branch, $chain)) {
            throw new RuntimeException("Unknown daisy chain branch: {$branch}");
        }
    }

    public function help() : int
    {
        return $this->cli->info(<<<'HELP'
            Daisy is a tool for managing daisy chains of git branches.

            Usage:
                daisy <command>

            Creation Commands:

                start <name>
                    Starts a new daisy chain of branches with the <name> prefix
                    by creating a special "start" branch. The "start" branch is
                    an empty commit with a message indicating the "root" branch
                    of the daisy chain. DO NOT work on this special "start"
                    branch. It is for tracking the "root" branch only.

                add
                    Adds a numbered branch to the end of the daisy chain.

                temp <suffix>
                    Adds a temporary offshoot branch from the current numbered
                    branch, appending a "-<suffix>" after the number. Note that
                    the previous branch for a temporary branch is always the
                    numbered branch from which it is an offshoot.

            Navigation Commands:

                first
                    Switches to the first numbered branch in the daisy chain.

                prev [<steps>]
                    Switches to the previous numbered branch in the daisy chain,
                    or any number of <steps> back to the first numbered branch.

                next [<steps>]
                    Switches to the next numbered branch in the daisy chain, or
                    any number of <steps> forward to the last numbered branch.

                last
                    Switches to the last numbered branch in the daisy chain.

                goto (root | start | <number>[-<suffix>])
                    Switches to the specified branch in the daisy chain.

            Info Commands:

                branch
                    Shows the current branch in the daisy chain.

                chain
                    Shows the list of branches in the daisy chain, including
                    temporary offshoot branches.

                diff [plain]
                    Shows the color diff between the current branch in the daisy
                    chain and the previous numbered branch (or the "root" branch
                    if on the first numbered branch). Passing 'plain' shows a
                    plain (not color) diff. To paginate a color diff, pipe the
                    output to `less -r`.

            Deletion Commands:

                drop (<number>[-<suffix>])
                    Deletes the specified branch from the daisy chain using
                    `git branch -d`.

                kill (<number>[-<suffix>])
                    Deletes the specified branch from the daisy chain using
                    `git branch -D`.

            Management Commands:

                open
                    Opens a comparison request at Github for the current branch
                    against the previous numbered branch (or the "root" branch
                    if on the first numbered branch).

                send
                    Force-pushes the current branch in the daisy chain to the
                    remote origin, setting the upstream if not already set.

                sync
                    Pulls from the remote origin, then rebases the current
                    branch on the previous numbered branch (or the "root" branch
                    if on the first numbered branch).

            HELP,
        );
    }
}

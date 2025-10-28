# pmjones/daisy

[![PDS Skeleton](https://img.shields.io/badge/pds-skeleton-blue.svg?style=flat-square)](https://github.com/php-pds/skeleton)
[![PDS Composer Script Names](https://img.shields.io/badge/pds-composer--script--names-blue?style=flat-square)](https://github.com/php-pds/composer-script-names)

## Overview

Daisy is a tool for managing daisy chains of git branches.

Before [stacking](https://www.stacking.dev/) was cool, I had some bash scripts
to do something similar for my refactoring work.

At the time, I thought of these sequentially dependent git branches as a "daisy
chain". This package takes those bash scripts and collects them into something
more manageable.

## Installation

There are no releases yet; install the 0.x branch with Composer:

```
% composer global require pmjones/daisy 0.x-dev
```

I find it convenient to symlink it to a global path:

```
% ln -s ~/.composer/vendor/bin/daisy /usr/local/bin/daisy
```

## Workflow

From a "root" branch (e.g. `master` or `prod`) start a daisy chain like so:

```
% daisy start refactor
Starting a new daisy chain.
Created daisy chain refactor_@_ from prod.
On branch refactor_@_0
nothing to commit, working tree clean
%
```

You will now find two new branches with the `_@_` sigil in them, indicating they
are part of a daisy chain:

- `refactor_@_` is a "start" branch, and keeps track of which branch served as
  the root for the daisy chain; and,

- `refactor_@_0` is the first numbered branch in the daisy chain itself.

Make your changes on the numbered branch and commit them. You can then add
another branch to the daisy chain:

```
% daisy add
Adding a new branch to the daisy chain.
On branch refactor_@_1
nothing to commit, working tree clean
%
```

(The `_@_1` branch depends on the `_@_0` branch.) Make some changes on this added
branch and commit them.

Keep adding branches to the chain until you're done.

You can find out where you are in the in the daisy chain with `branch`:

```
% daisy branch
refactor_@_3
%
```

You can see all the branches in the daisy chain with `chain`:

```
% daisy chain
refactor_@_0
refactor_@_1
refactor_@_2
refactor_@_3
refactor_@_4
refactor_@_5
%
```

If you need to go back and make some fixes, use `prev` to go back one or more
steps:

```
% daisy branch
refactor_@_5
% daisy prev
refactor_@_4
% daisy prev 2
refactor_@_2
%
```

Make your changes, commit them, and then step forward one branch at a time to
sync each branch first with the remote (via `git pull`) and then with its
previous branch (via `rebase`):

```
% daisy branch
refactor_@_2
% daisy next
refactor_@_3
% daisy sync # git pull && git rebase refactor_@_2
%
```

If there are conflicts, you need to resolve them as with any rebase; `git add -A`,
`git rebase --continue`, and so on.

Send each branch to the remote origin with `send`. The first time, this will set
the upstream and push the branch:

```
% daisy branch
refactor_@_0
% daisy send # git push -u origin refactor_@_0
%
```

Therafter, `send` will force-push the branch:

```
% daisy branch
refactor_@_0
% daisy send # git push --force-with-lease
%
```

After you `send` a branch you can `open` it at GitHub to compare it with the
previous branch. From there you can start a new PR or go to an existing PR.

```
% daisy branch
refactor_@_3
% daisy open # https://github.com/owner/repo/compare/refactor_@_2..refactor_@_3
%
```

You can `goto` any numbered branch directly:

```
% daisy goto 1
refactor_@_1
%
```

## Temporary Offshoot Branches

You may sometimes need a temporary offshoot branch that is based on a numbered
branch. These offshoots are not part of the daisy chain sequence, and can be
useful for short-lived experiments.

To start a temporary branch, issue `temp` with a suffix name for the offshoot:

```
% daisy branch
refactor_@_2
% daisy temp SITE
Creating a temporary branch from the daisy chain.
On branch refactor_@_2-SITE
%
```

Each offshoot is a "dead end" in the sense that you cannot `add` a numbered
branch from it, and you cannot create another `temp` branch from it. (You can
create as many temp branches from the same numbered branch as you like.)

You can move back to the `prev` branch from the temporary offshoot; this will
switch back to the numbered branch the offshoot came from.

```
% daisy branch
refactor_@_2-SITE
% daisy prev
refactor_@_2
```

Switching to the `next` branch from a temporary offshoot will switch to the next
numbered branch.

```
% daisy branch
refactor_@_2-SITE
% daisy next
refactor_@_3
%
```

Likewise, switching to the `next` branch from a numbered branch will skip over
any temporary branches:

```
% daisy branch
refactor_@_2
% daisy next
refactor_@_3
%
```

To switch to a temporary offshoot branch, use `goto`:

```
% daisy branch
refactor_@_2
% daisy goto 2-SITE
refactor_@_2-SITE
%
```

When you `diff` or `sync` a temporary offshoot branch, it will be against the
numbered branch it is based on.

You can `send` and `open` a temporary offshoot branch like any other numbered
branch.




## Help

```
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
```

# pmjones/daisy

[![PDS Skeleton](https://img.shields.io/badge/pds-skeleton-blue.svg?style=flat-square)](https://github.com/php-pds/skeleton)
[![PDS Composer Script Names](https://img.shields.io/badge/pds-composer--script--names-blue?style=flat-square)](https://github.com/php-pds/composer-script-names)

## Overview

Daisy is a tool for managing daisy chains of git branches.

Before [stacking](https://www.stacking.dev/) was cool, I had some bash scripts
to do something similar for my refactoring work.

At the time I thought of these sequentially dependent git branches as a "daisy
chain". This package takes those bash scripts and collects them into something
more manageable.

## Workflow

From a "root" branch (e.g. `master` or `prod`) start a daisy chain like so:

```
% daisy start refactor
Starting a new daisy chain.
Created daisy chain refactor_@_ from main.
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

## Help

```
Usage:
    daisy <command>

Creation Commands:

    start <name>
        Starts a new daisy chain of branches with the <name>
        prefix by creating a non-numbered "start" branch with an
        empty commit with a message indicating the "root" branch of
        the daisy chain. DO NOT work on this branch. It is for
        tracking the "root" branch only.

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

    diff [plain]
        Shows the color diff between the current branch in the daisy
        chain and the previous branch (numbered or "root"). Passing
        'plain' shows a plain (not color) diff.

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
        the previous branch (numbered or "root").
```

<?php
declare(strict_types=1);

namespace pmjones\Daisy;

readonly class Daisy
{
    //                         1               2            3 4
    protected const REGEX = '/^(?<prefix>.+)_@_(?<number>\d+(-(?<suffix>[A-Z-a-z-0-9-_]+))?)?$/';
    //                                     1                                          43 2

    public static function isValid(string $branch) : bool
    {
        return (bool) self::fromBranch($branch);
    }

    public static function isNumbered(string $branch) : bool
    {
        $daisy = self::fromBranch($branch);
        return $daisy?->number !== null;
    }

    public static function isSuffixed(string $branch) : bool
    {
        $daisy = self::fromBranch($branch);
        return $daisy?->suffix !== null;
    }

    public static function fromStart(string $prefix) : self
    {
        return new self(
            branch: "{$prefix}_@_",
            prefix: $prefix,
            number: null,
            suffix: null,
        );
    }

    public static function fromBranch(string $branch) : ?self
    {
        if (! preg_match(self::REGEX, $branch, $matches, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return new self(
            branch: $matches[0],
            prefix: $matches['prefix'],
            number: $matches['number'] === null ? null : (int) $matches['number'],
            suffix: $matches['suffix'],
        );
    }

    public function __construct(
        public string $branch,
        public string $prefix,
        public ?int $number,
        public ?string $suffix,
    ) {
    }

    public function withNumber(int $number) : self
    {
        return new self(
            branch: "{$this->prefix}_@_{$number}",
            prefix: $this->prefix,
            number: $number,
            suffix: null,
        );
    }

    public function withSuffix(?string $suffix) : ?self
    {
        if (is_string($suffix) && ! ctype_graph($suffix)) {
            return null;
        }

        return new self(
            branch: "{$this->prefix}_@_{$this->number}-{$suffix}",
            prefix: $this->prefix,
            number: $this->number,
            suffix: $suffix,
        );
    }

    public function withoutSuffix() : self
    {
        return new self(
            branch: "{$this->prefix}_@_{$this->number}",
            prefix: $this->prefix,
            number: $this->number,
            suffix: null,
        );
    }

    public function withNextNumber() : self
    {
        return $this->withNumber(
            $this->number === null ? 0 : $this->number + 1
        );
    }

    public function getStartBranch() : string
    {
        return "{$this->prefix}_@_";
    }

    public function getNumberedBranch(int $number) : string
    {
        return "{$this->prefix}_@_{$number}";
    }
}

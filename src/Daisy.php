<?php
declare(strict_types=1);

namespace pmjones\Daisy;

readonly class Daisy
{
    protected const REGEX = '/^(.+)_@_(\d+)?$/';

    public static function isValid(string $branch) : bool
    {
        return (bool) self::fromBranch($branch);
    }

    public static function isNumbered(string $branch) : bool
    {
        $daisy = self::fromBranch($branch);
        return $daisy?->number !== null;
    }

    public static function fromStart(string $prefix) : self
    {
        return new self(
            branch: "{$prefix}_@_",
            prefix: $prefix,
            number: null,
        );
    }

    public static function fromBranch(string $branch) : ?self
    {
        if (! preg_match(self::REGEX, $branch, $matches, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return new self(
            branch: $matches[0],
            prefix: $matches[1],
            number: $matches[2] === null ? null : (int) $matches[2],
        );
    }

    public function __construct(
        public string $branch,
        public string $prefix,
        public ?int $number,
    ) {
    }

    public function withNumber(int $number) : self
    {
        return new self(
            branch: "{$this->prefix}_@_{$number}",
            prefix: $this->prefix,
            number: $number,
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

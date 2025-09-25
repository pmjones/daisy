<?php
declare(strict_types=1);

namespace pmjones\Daisy;

use pmjones\Stdlog\Stdlog;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DaisyCli
{
    public bool $verbose = false;

    public function __construct(
        protected LoggerInterface $logger = new Stdlog(),
    ) {
    }

    public function run(string $command) : DaisyCli_Result
    {
        if ($this->verbose) {
            $this->logger->debug("> $command");
        }

        $lastLine = exec("{$command} 2>&1", $output, $exitCode);

        $result = new DaisyCli_Result(
            $command,
            $output,
            $exitCode,
            (string) $lastLine
        );

        if ($this->verbose) {
            $message = '';

            foreach ($output as $line) {
                $message .= "< $line" . PHP_EOL;
            }

            $this->logger->debug($message);
        }

        return $result;
    }

    public function runOrThrow(string $command, string $message) : DaisyCli_Result
    {
        $result = $this->run("git config --get color.ui");
        $color = $result->lastLine;
        $this->run("git config --set color.ui always");
        $result = $this->run($command);
        $this->run("git config --set color.ui {$color}");

        if ($result->exitCode) {
            $message .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $result->output);
            throw new RuntimeException($message, $result->exitCode);
        }

        return $result;
    }

    public function error(string $message, int $exitCode = 1) : int
    {
        $this->logger->error($message);
        return $exitCode;
    }

    public function info(string $message) : int
    {
        $this->logger->info($message);
        return 0;
    }
}

readonly class DaisyCli_Result
{
    /**
     * @param string[] $output
     */
    public function __construct(
        public string $command,
        public array $output,
        public int $exitCode,
        public string $lastLine,
    ) {
    }
}

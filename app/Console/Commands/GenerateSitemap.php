<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class GenerateSitemap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sitemap:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trigger sitemap generation logic in the frontend project';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting sitemap generation at ' . now());

        // Use realpath to get a clean, absolute path from a relative definition
        // This ensures the log looks better and the path is valid.
        $frontendPath = realpath(base_path('../front-end-dev'));

        if (!$frontendPath || !is_dir($frontendPath)) {
            $this->error("❌ Frontend directory not found.");
            Log::error("Sitemap generation failed: Frontend directory not found relative to " . base_path());
            return Command::FAILURE;
        }

        $this->info("📁 Frontend path: {$frontendPath}");

        // Detect NPM path. In some environments (like cron), 'npm' is not in the $PATH.
        // We first check if a custom path is defined in .env
        $npmBinary = env('NPM_BINARY_PATH', 'npm');

        // Check if the binary exists if a full path was provided
        if ($npmBinary !== 'npm' && !file_exists($npmBinary)) {
            $this->error("❌ Custom NPM binary not found at: {$npmBinary}");
            return Command::FAILURE;
        }

        $this->info("⚙️ Using NPM binary: {$npmBinary}");

        // Prepend the npm/node directory to the PATH to ensure 'node' can be found
        $binPath = dirname($npmBinary);
        $currentPath = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        $env = [
            'PATH' => $binPath . PATH_SEPARATOR . $currentPath,
        ];

        // Command to run: $npmBinary run script
        $process = Process::fromShellCommandline("{$npmBinary} run script", $frontendPath, $env);
        $process->setTimeout(600);

        try {
            $process->run(function ($type, $buffer) {
                if (Process::ERR === $type) {
                    $this->output->write('<error>' . $buffer . '</error>');
                } else {
                    $this->output->write($buffer);
                }
            });

            if (!$process->isSuccessful()) {
                $this->error('❌ Script failed.');
                Log::error('Sitemap generation failed', [
                    'exit_code' => $process->getExitCode(),
                    'error' => $process->getErrorOutput()
                ]);
                return Command::FAILURE;
            }

            $this->info('🎉 Sitemap generation completed successfully!');
            Log::info('Sitemap generation completed successfully.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ An error occurred: ' . $e->getMessage());
            Log::error('Sitemap generation exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

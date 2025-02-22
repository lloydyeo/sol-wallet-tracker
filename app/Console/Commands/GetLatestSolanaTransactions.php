<?php

namespace App\Console\Commands;

use App\Services\SolanaTransactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GetLatestSolanaTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'solana:get-latest-transactions {slpHash} {--limit=10}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retrieves the latest Solana transactions for a given SLP hash.';

    /**
     * The Solana transaction service.
     *
     * @var SolanaTransactionService
     */
    protected $solanaTransactionService;

    /**
     * Create a new command instance.
     *
     * @param SolanaTransactionService $solanaTransactionService
     */
    public function __construct(SolanaTransactionService $solanaTransactionService)
    {
        parent::__construct();

        $this->solanaTransactionService = $solanaTransactionService;
    }

    public function handle() {
        dd($this->solanaTransactionService->getTokenHoldingsOfWallet('5WXqdUFTVHjKVoTcCviRQWyDpvZmWdcyMZxrBsbRyMjZ'));
    }

    public function getLargestAccountsExample()
    {
        try {
            // Get the 20 largest accounts (no filter)
            $largestAccounts = $this->solanaTransactionService->getLargestAccounts();

            // Get the 20 largest circulating accounts
            $largestCirculatingAccounts = $this->solanaTransactionService->getLargestAccounts('circulating');

            // Get the 20 largest non-circulating accounts
            $largestNonCirculatingAccounts = $this->solanaTransactionService->getLargestAccounts('nonCirculating');

            // Process the results (e.g., display them in a view, store them in a database)
            dd($largestAccounts, $largestCirculatingAccounts, $largestNonCirculatingAccounts);

        } catch (\App\Exceptions\SolanaTransactionRetrievalException $e) {
            // Handle the exception (e.g., display an error message)
            echo "Error: " . $e->getMessage();
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle2()
    {
        $slpHash = $this->argument('slpHash');
        $limit = $this->option('limit'); // Defaults to 10 if not specified in the command

        $this->info("Retrieving the latest {$limit} Solana transactions for SLP hash: {$slpHash}");

        try {
            $transactions = $this->solanaTransactionService->getTransactionsBySlpHash($slpHash, $limit);

            if (empty($transactions)) {
                $this->info('No transactions found for this SLP hash.');
                return 0; // Return success code
            }

            $this->info('Found ' . count($transactions) . ' transactions.');

            // Output the transactions (you can customize this)
            foreach ($transactions as $transaction) {
                $this->line('Transaction Signature: ' . $transaction['transaction']['signatures'][0]);
                $this->line('Block Time: ' . ($transaction['blockTime'] ?? 'N/A')); // Use null coalescing operator
                $this->line('--------------------------------------');
            }

            $this->info('Successfully retrieved and displayed transactions.');
            return 0; // Return success code

        } catch (\App\Exceptions\SolanaTransactionRetrievalException $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            Log::error('Command Error: ' . $e->getMessage());
            return 1; // Return error code
        }
    }
}

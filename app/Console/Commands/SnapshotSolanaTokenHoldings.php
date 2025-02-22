<?php

namespace App\Console\Commands;

use App\Services\SolanaTransactionService;
use Google\Client;
use Illuminate\Console\Command;
use Google\Service\Sheets;

class SnapshotSolanaTokenHoldings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'solana:snapshot-latest-solana-token-holdings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retrieves the latest Solana transactions for a given SLP hash.';

    public function handle(SolanaTransactionService $solanaTransactionService)
    {
        $wallets = [];

        $token = 'BfxhMerBkBhRUGn4tX5YrBRqLqN8VjvUXHhU7K9Fpump';

        $spreadsheetId = '1TpCvR04HrO2fRXvvhnbDBSMAzl2PX-cLS-bsflLkY9M';

        $sheetsClient = $this->getSheetsClient();

        $response = $sheetsClient->spreadsheets_values->get($spreadsheetId, 'Sheet1');

        $values = $response->getValues();

        foreach ($values as $rowIdx => $value) {

            if (!$rowIdx) {
                continue;
            }

            $wallet = [
                'Who' => $value[0],
                'Wallet Address' => $value[1]
            ];

            if (isset($value[2])) {
                $wallet['Yesterday'] = $value[2];
            }

            if (isset($value[3])) {
                $wallet['Today'] = $value[3];
            }

            $wallets[] = $wallet;

        }

        $data = [];

        foreach ($wallets as $rowIdx => $wallet) {

            $todayTokenHoldings = $solanaTransactionService->getTokenHoldingsOfWallet($wallet['Wallet Address'], $token);

            if (!$todayTokenHoldings) {
                continue;
            }

            $todayNum = $todayTokenHoldings['account']['data']['parsed']['info']['tokenAmount']['uiAmount'];

            if (!isset($wallet['Today'])) {
                $wallet['Yesterday'] = $todayNum;
                $wallet['Today'] = $todayNum;
                $wallet['Diff'] = 0;
            } else {
                $wallet['Yesterday'] = $wallet['Today'];
                $wallet['Today'] = $todayNum;
                $wallet['Diff'] = $todayNum - $wallet['Yesterday'];
            }

            $sheetRow = $rowIdx + 2;
            $rowRange = "Sheet1!$sheetRow:$sheetRow";
            $rowBody = new \Google_Service_Sheets_ValueRange([
                'range' => $rowRange,
                'values' => [array_values($wallet)]
            ]);

            $data[] = $rowBody;

            $this->info("Pushed to row: " . $sheetRow . ", values: "  . implode(',', array_values($wallet)));

        }

        $body = new \Google_Service_Sheets_BatchUpdateValuesRequest([
            'valueInputOption' => 'RAW',
            'data' => $data
        ]);

        $sheetsClient->spreadsheets_values->batchUpdate(
            $spreadsheetId,
            $body
        );

    }

    public function getSheetsClient() : Sheets
    {
        $client = new Client();
        $client->setApplicationName('Lynk - Wallet Tracker');
        $client->setScopes([Sheets::SPREADSHEETS]);

        $path = storage_path('app/private/gen-lang-client-0419186980-080d4735bcb6.json');
        $client->setAuthConfig($path);

        return new Sheets($client);
    }

}

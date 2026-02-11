<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Console\Command;
use App\Models\Income;

class SyncIncomesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:sync-incomes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch stocks from API and save to database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Начинаем загрузку Доходов ...");

        $url = env('WB_API_URL') . '/incomes';
        $key = env('WB_API_KEY');
        $page = 1;
        $dateFrom = '2026-02-10';
        $dateTo = now()->toDateString();


        // Цикл do-while, так как не знаем общее кол-во страниц
        do {
            $this->info("Запрашиваем страницу {$page} ...");

            try {
                // Отправляем курьера притворившись Postman
                $response = Http::withHeaders([
                    'User-Agent'      => 'PostmanRuntime/7.51.1',
                    'Accept'          => '*/*',
                    'Cache-Control'   => 'no-cache',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Connection'      => 'keep-alive',
                ])
                ->timeout(30)
                ->withoutVerifying()
                ->get($url, [
                    "dateFrom" => $dateFrom,
                    "dateTo"   => $dateTo,
                    "page"     => $page,
                    "key"      => $key,
                    "limit"    => 100,
                ]);

                // Проверяем, не вернул ли сервер ошибку
                if ($response->failed()) {
                    $this->error("Ошибка сервера на странице {$page}: " . $response->status());
                    return;
                }

                $json = $response->json();

            } catch (\Exception $e) {
                // Если склад вообще не ответил (упал интернет или неверный порт)
                $this->error("Критическая ошибка соединения: " . $e->getMessage());
                return;
            }

            $data = $json["data"] ?? [];

            // Проверяем, есть ли вообще данные на этой странице
            if (empty($data)) {
                 $this->info("Склад пуст или данные закончились.");
                 break;
            }

            // Раскладываем по полкам
            foreach ($data as $item) {
                Income::updateOrCreate(
                    [
                        // В одной поставке могут быть разные товары, поэтому ищем по ID поставки + штрихкоду
                        "income_id" => $item["income_id"],
                        "barcode" => $item["barcode"],
                    ],
                    [
                        "number" => $item["number"],
                        "date" => $item["date"],
                        "last_change_date" => $item["last_change_date"],
                        "supplier_article" => $item["supplier_article"],
                        "tech_size" => $item["tech_size"],
                        "quantity" => $item["quantity"],
                        "total_price" => $item["total_price"],
                        "date_close" => $item["date_close"],
                        "warehouse_name" => $item["warehouse_name"],
                        "nm_id" => $item["nm_id"],
                    ]
                );
            }

            // Проверяем, есть ли ещё страницы
            $lastPage = $json["meta"]["last_page"] ?? 1;
            $page++;

        } while ($page <= $lastPage);

        $this->info('Доходы успешно синхронизированы!');
    }
}

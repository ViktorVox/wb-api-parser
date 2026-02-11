<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Stock;

class SyncStocksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "api:sync-stocks";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Fetch stocks from API and save to database";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Начинаем загрузку Складов ...");

        $url = env('WB_API_URL') . '/stocks';
        $key = env('WB_API_KEY');
        $page = 1;
        $dateFrom = now()->toDateString(); // Сегодняшняя дата (Y-m-d)

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
                Stock::updateOrCreate(
                    [
                        "date" => $item["date"],
                        "warehouse_name" => $item["warehouse_name"],
                        "nm_id" => $item["nm_id"],
                    ],
                    [
                        "last_change_date" => $item["last_change_date"],
                        "supplier_article" => $item["supplier_article"],
                        "tech_size" => $item["tech_size"],
                        "barcode" => $item["barcode"],
                        "quantity" => $item["quantity"],
                        "is_supply" => $item["is_supply"],
                        "is_realization" => $item["is_realization"],
                        "quantity_full" => $item["quantity_full"],
                        "in_way_to_client" => $item["in_way_to_client"],
                        "in_way_from_client" => $item["in_way_from_client"],
                        "subject" => $item["subject"],
                        "category" => $item["category"],
                        "brand" => $item["brand"],
                        "sc_code" => $item["sc_code"],
                        "price" => $item["price"],
                        "discount" => $item["discount"],
                    ]
                );
            }

            // Проверяем, есть ли ещё страницы
            $lastPage = $json["meta"]["last_page"] ?? 1;
            $page++;

        } while ($page <= $lastPage);

        $this->info('Склады успешно синхронизированы!');
    }
}

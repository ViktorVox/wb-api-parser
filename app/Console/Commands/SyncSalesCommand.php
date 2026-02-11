<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Http;
use \Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use App\Models\Sale;

class SyncSalesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:sync-sales';

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
        $this->info("Начинаем загрузку Продаж ...");

        $url = env('WB_API_URL') . '/sales';
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
                 $this->info("Продажи пусты или данные закончились.");
                 break;
            }

            // Раскладываем по полкам
            $batch = [];
            foreach ($data as $item) {
                $batch[] = [
                    "sale_id" => $item["sale_id"],
                    "g_number" => $item["g_number"],
                    "date" => $item["date"],
                    "last_change_date" => $item["last_change_date"],
                    "supplier_article" => $item["supplier_article"],
                    "tech_size" => $item["tech_size"],
                    "barcode" => $item["barcode"],
                    "total_price" => $item["total_price"],
                    "discount_percent" => $item["discount_percent"],
                    "is_supply" => $item["is_supply"],
                    "is_realization" => $item["is_realization"],
                    "promo_code_discount" => $item["promo_code_discount"],
                    "warehouse_name" => $item["warehouse_name"],
                    "country_name" => $item["country_name"],
                    "oblast_okrug_name" => $item["oblast_okrug_name"],
                    "region_name" => $item["region_name"],
                    "income_id" => $item["income_id"],
                    "odid" => $item["odid"],
                    "spp" => $item["spp"],
                    "for_pay" => $item["for_pay"],
                    "finished_price" => $item["finished_price"],
                    "price_with_disc" => $item["price_with_disc"],
                    "nm_id" => $item["nm_id"],
                    "subject" => $item["subject"],
                    "category" => $item["category"],
                    "brand" => $item["brand"],
                    "is_storno" => $item["is_storno"],
                ];
            }

            if (!empty($batch)) {
                // Пингуем базу и восстанавливаем соединение
                DB::purge();
                DB::reconnect();

                // Дробим большую партию (100) на две по 50, чтобы пролезть в лимиты облака
                $chunks = array_chunk($batch, 10);

                foreach ($chunks as $chunk) {
                    Sale::upsert(
                        $chunk,
                        ['sale_id'], // Уникальный ключ
                        ['g_number', 'date', 'last_change_date', 'supplier_article', 'tech_size', 'barcode', 'total_price', 'discount_percent', 'is_supply', 'is_realization', 'promo_code_discount', 'warehouse_name', 'country_name', 'oblast_okrug_name', 'region_name', 'income_id', 'odid', 'spp', 'for_pay', 'finished_price', 'price_with_disc', 'nm_id', 'subject', 'category', 'brand', 'is_storno']
                    );
                }

                $this->output->write('End');
            }

            $this->newLine();

            // Проверяем, есть ли ещё страницы
            $lastPage = $json["meta"]["last_page"] ?? 1;
            $page++;

        } while ($page <= $lastPage);

        $this->info('Продажи успешно синхронизированы!');
    }
}

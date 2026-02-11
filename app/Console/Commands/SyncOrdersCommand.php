<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Http;
use \Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use App\Models\Order;

class SyncOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:sync-orders';

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
        $this->info("Начинаем загрузку Заказов ...");

        $url = env('WB_API_URL') . '/orders';
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
                 $this->info("Заказы пусты или данные закончились.");
                 break;
            }

            // Раскладываем по полкам
            $batch = [];
            foreach ($data as $item) {
                $batch[] = [
                    "odid" => $item["odid"],
                    "g_number" => $item["g_number"],
                    "date" => $item["date"],
                    "last_change_date" => $item["last_change_date"],
                    "supplier_article" => $item["supplier_article"],
                    "tech_size" => $item["tech_size"],
                    "barcode" => $item["barcode"],
                    "total_price" => $item["total_price"],
                    "discount_percent" => $item["discount_percent"],
                    "warehouse_name" => $item["warehouse_name"],
                    "oblast" => $item["oblast"],
                    "income_id" => $item["income_id"],
                    "nm_id" => $item["nm_id"],
                    "subject" => $item["subject"],
                    "category" => $item["category"],
                    "brand" => $item["brand"],
                    "is_cancel" => $item["is_cancel"],
                    "cancel_dt" => $item["cancel_dt"],
                ];
            }

            if (!empty($batch)) {
                // Пингуем базу и жестко сбрасываем старое соединение
                DB::purge();
                DB::reconnect();

                // Дробим партию на коробки по 10 штук
                $chunks = array_chunk($batch, 10);

                foreach ($chunks as $chunk) {
                    Order::upsert(
                        $chunk,
                        ['odid', 'g_number'], // Уникальный составной ключ
                        ['date', 'last_change_date', 'supplier_article', 'tech_size', 'barcode', 'total_price', 'discount_percent', 'warehouse_name', 'oblast', 'income_id', 'nm_id', 'subject', 'category', 'brand', 'is_cancel', 'cancel_dt']
                    );
                }

                $this->output->write('End');
            }

            $this->newLine();

            // Проверяем, есть ли ещё страницы
            $lastPage = $json["meta"]["last_page"] ?? 1;
            $page++;

        } while ($page <= $lastPage);

        $this->info('Заказы успешно синхронизированы!');
    }
}

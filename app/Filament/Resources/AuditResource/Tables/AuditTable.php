<?php

namespace App\Filament\Resources\AuditResource\Tables;

use App\Filament\Resources\AuditResource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AuditTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->query(AuditResource::getEloquentQuery())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('event')
                    ->label('操作類型')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'restored' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'created' => '創建',
                        'updated' => '更新',
                        'deleted' => '刪除',
                        'restored' => '恢復',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('操作對象')
                    ->formatStateUsing(fn(string $state): string => class_basename($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('對象ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('操作人')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP地址')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('操作時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('操作類型')
                    ->options([
                        'created' => '創建',
                        'updated' => '更新',
                        'deleted' => '刪除',
                        'restored' => '恢復',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('開始日期'),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('結束日期'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->headerActions([
                Action::make('exportExcel')
                    ->label('匯出Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->successNotificationTitle('匯出完成')
                    ->action(function (Action $action, $livewire) {
                        // 取得Livewire實例中已套用所有篩選條件的查詢
                        // getTableQueryForExport() 自動套用filters, search, sorting
                        $query = $livewire->getTableQueryForExport();

                        // 建立Excel
                        $spreadsheet = new Spreadsheet();
                        $sheet = $spreadsheet->getActiveSheet();
                        $sheet->setTitle('操作記錄');

                        // 表頭
                        $headers = [
                            '記錄ID',
                            '操作類型',
                            '操作對象',
                            '對象ID',
                            '租戶',
                            '操作人',
                            'IP地址',
                            '訪問URL',
                            '操作時間',
                            '修改前',
                            '修改後'
                        ];
                        $col = 1;
                        foreach ($headers as $header) {
                            $sheet->setCellValue(chr(64 + $col) . '1', $header);
                            $col++;
                        }

                        // 設定欄寬
                        $sheet->getColumnDimension('A')->setWidth(10);  // 記錄ID
                        $sheet->getColumnDimension('B')->setWidth(12);  // 操作類型
                        $sheet->getColumnDimension('C')->setWidth(15);  // 操作對象
                        $sheet->getColumnDimension('D')->setWidth(12);  // 對象ID
                        $sheet->getColumnDimension('E')->setWidth(15);  // 租戶
                        $sheet->getColumnDimension('F')->setWidth(15);  // 操作人
                        $sheet->getColumnDimension('G')->setWidth(15);  // IP地址
                        $sheet->getColumnDimension('H')->setWidth(40);  // 訪問URL
                        $sheet->getColumnDimension('I')->setWidth(20);  // 操作時間
                        $sheet->getColumnDimension('J')->setWidth(40);  // 修改前
                        $sheet->getColumnDimension('K')->setWidth(40);  // 修改後

                        // 設定標題列樣式
                        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
                        $sheet->getStyle('A1:K1')->getAlignment()->setHorizontal('center');

                        // 先計算總記錄數
                        $totalRecords = $query->count();

                        // 啟用自動換行
                        $sheet->getStyle('A2:K' . ($totalRecords + 1))->getAlignment()->setWrapText(true);

                        // 填寫資料 - 使用cursor()逐筆處理，避免記憶體問題
                        $row = 2;
                        foreach ($query->cursor() as $audit) {
                            // 操作類型翻譯
                            $event = match ($audit->event) {
                                'created' => '創建',
                                'updated' => '更新',
                                'deleted' => '刪除',
                                'restored' => '恢復',
                                default => $audit->event
                            };

                            // 格式化old_values
                            $oldValues = '-';
                            if (!empty($audit->old_values)) {
                                $lines = [];
                                foreach ($audit->old_values as $key => $value) {
                                    if (is_array($value) || is_object($value)) $value = json_encode($value);
                                    $lines[] = "{$key}: {$value}";
                                }
                                $oldValues = implode("\n", $lines);
                            }

                            // 格式化new_values
                            $newValues = '-';
                            if (!empty($audit->new_values)) {
                                $lines = [];
                                foreach ($audit->new_values as $key => $value) {
                                    if (is_array($value) || is_object($value)) $value = json_encode($value);
                                    $lines[] = "{$key}: {$value}";
                                }
                                $newValues = implode("\n", $lines);
                            }

                            // 寫入儲存格
                            $sheet->setCellValue('A' . $row, $audit->id);
                            $sheet->setCellValue('B' . $row, $event);
                            $sheet->setCellValue('C' . $row, class_basename($audit->auditable_type));
                            $sheet->setCellValue('D' . $row, $audit->auditable_id);
                            $sheet->setCellValue('E' . $row, $audit->tenant?->name ?? '-');
                            $sheet->setCellValue('F' . $row, $audit->user?->name ?? '-');
                            $sheet->setCellValue('G' . $row, $audit->ip_address ?? '-');
                            $sheet->setCellValue('H' . $row, $audit->url ?? '-');
                            $sheet->setCellValue('I' . $row, $audit->created_at?->format('Y-m-d H:i:s'));
                            $sheet->setCellValue('J' . $row, $oldValues);
                            $sheet->setCellValue('K' . $row, $newValues);

                            $row++;
                        }

                        // 建立暫存檔案
                        $fileName = 'audit-logs-' . now()->format('Y-m-d') . '.xlsx';
                        $temporaryPath = storage_path('app/temp/' . $fileName);

                        // 確保暫存目錄存在
                        if (!file_exists(storage_path('app/temp'))) {
                            mkdir(storage_path('app/temp'), 0755, true);
                        }

                        // 儲存Excel到暫存路徑
                        $writer = new Xlsx($spreadsheet);
                        $writer->save($temporaryPath);

                        // 回傳下載回應，下載後自動刪除暫存檔
                        return response()->download($temporaryPath, $fileName, [
                            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])->deleteFileAfterSend(true);
                    }),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}

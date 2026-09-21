<?php

namespace App\Filament\Resources\SalesOrders\Concerns;

use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrder;
use App\Models\SalesOrderItemAllocation;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

trait HasSalesOrderCancelledPurchaseRecoveryAction
{
    #[Locked]
    public ?string $cancelledPurchaseRecoveryToken = null;

    protected function cancelledPurchaseRecoveryAction(): Action
    {
        return Action::make('recoverCancelledPurchase')
            ->label('Reprendre après annulation fournisseur')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (SalesOrder $record): bool => $record->status === SalesOrder::STATUS_CONFIRMED)
            ->authorize(fn (SalesOrder $record): bool => SalesOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage('Cette action est réservée aux administrateurs et gestionnaires.')
            ->modalHeading('Confirmer la reprise vers un fournisseur alternatif')
            ->modalDescription('Sélectionnez les lignes à reprendre. Aucun achat ne sera généré : seule une nouvelle allocation sera enregistrée.')
            ->modalSubmitActionLabel('Confirmer les reprises sélectionnées')
            ->mountUsing(function (Schema $schema, SalesOrder $record): void {
                abort_unless(SalesOrderResource::canEdit($record), 403);
                if ($this->cancelledPurchaseRecoveryToken) {
                    session()->forget('cancelled-purchase-recovery.'.$this->cancelledPurchaseRecoveryToken);
                }
                ['offers' => $offers, 'reasons' => $reasons] = SalesOrderItemAllocation::previewCancelledPurchaseRecoveriesFor($record);
                $this->cancelledPurchaseRecoveryToken = (string) Str::uuid();
                // L'offre reste en session serveur, jamais dans des champs cachés.
                session()->put('cancelled-purchase-recovery.'.$this->cancelledPurchaseRecoveryToken, [
                    'user_id' => auth()->id(),
                    'sales_order_id' => $record->id,
                    'expires_at' => now()->addMinutes(15)->timestamp,
                    'offers' => $offers,
                    'reasons' => $reasons,
                ]);
                $schema->fill(['allocations' => []]);
            })
            ->schema([
                CheckboxList::make('allocations')
                    ->label('Achat annulé → fournisseur alternatif — quantité')
                    ->options(function (): array {
                        $options = [];
                        foreach ($this->cancelledPurchaseRecoverySession()['offers'] ?? [] as $id => $offer) {
                            $options[$id] = 'Ligne #'.$offer['sales_order_item_id'].' — '.$offer['purchase_reference']
                                .' → '.$offer['supplier_name'].' — '.$offer['quantity'].' unité(s)';
                        }

                        return $options;
                    })
                    ->helperText(fn (): string => implode(' ', $this->cancelledPurchaseRecoverySession()['reasons'] ?? []))
                    ->required(),
            ])
            ->action(function (SalesOrder $record, array $data): void {
                abort_unless(SalesOrderResource::canEdit($record), 403);
                $context = $this->cancelledPurchaseRecoverySession();
                $ids = array_values(array_unique(array_map('strval', $data['allocations'] ?? [])));
                if (! $context || $context['user_id'] !== auth()->id()
                    || $context['sales_order_id'] !== $record->id || $context['expires_at'] < now()->timestamp
                    || $ids === [] || array_diff($ids, array_map('strval', array_keys($context['offers']))) !== []) {
                    Notification::make()->title('Confirmation invalide ou expirée')->danger()->send();

                    return;
                }

                // Une confirmation est consommée une seule fois, même en cas de refus.
                session()->forget('cancelled-purchase-recovery.'.$this->cancelledPurchaseRecoveryToken);
                $messages = [];
                $failed = false;
                foreach ($ids as $id) {
                    try {
                        $allocation = SalesOrderItemAllocation::findOrFail($id);
                        if ($allocation->salesOrderItem()->value('sales_order_id') !== $record->id) {
                            throw new \Exception('Cette ligne n’appartient pas à la vente affichée.');
                        }
                        SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($allocation, $context['offers'][$id]);
                        $messages[] = 'Allocation #'.$id.' : reprise effectuée.';
                    } catch (\Exception $e) {
                        $failed = true;
                        $messages[] = 'Allocation #'.$id.' : '.$e->getMessage();
                    }
                }
                Notification::make()->title('Reprise après annulation')
                    ->body(implode(' ', $messages))->color($failed ? 'warning' : 'success')->send();
            });
    }

    private function cancelledPurchaseRecoverySession(): ?array
    {
        return $this->cancelledPurchaseRecoveryToken
            ? session()->get('cancelled-purchase-recovery.'.$this->cancelledPurchaseRecoveryToken)
            : null;
    }
}

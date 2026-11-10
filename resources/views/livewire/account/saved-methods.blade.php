{{--
    Saved payment methods. See Livewire\Account\SavedPaymentMethods.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
<div class="flex flex-col gap-6" data-paypal-saved-methods>
    @if ( $deletions > 0 )
        {{-- After a delete, focus (left on the removed method, or nowhere) moves to the list heading. The modal hands focus back as it closes, so this waits for it. --}}
        <span
            hidden
            wire:key="paypal-saved-method-focus-{{ $deletions }}"
            x-data
            x-init="setTimeout( () => {
                if ( document.activeElement && document.activeElement !== document.body && document.activeElement.isConnected ) {
                    return;
                }

                $el.closest( '[data-paypal-saved-methods]' )?.querySelector( '[data-paypal-saved-methods-heading]' )?.focus();
            }, 100 )"
        ></span>
    @endif

    <h2 class="sr-only" tabindex="-1" data-paypal-saved-methods-heading>{{ __( 'Saved payment methods' ) }}</h2>

    @if ( $failed )
        <x-artisanpack-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-base-content!" :title="__( 'Your payment methods couldn\'t be loaded' )" :description="__( 'Try again in a moment.' )" data-paypal-saved-methods-failed />
    @elseif ( [] === $methods )
        <x-artisanpack-alert icon="o-credit-card" class="alert-info alert-soft text-base-content!" :title="__( 'No saved payment methods' )" :description="__( 'Choose to save a card or your PayPal account at checkout to pay faster next time.' )" data-paypal-no-saved-methods />
    @else
        <ul class="grid gap-4 md:grid-cols-2" aria-label="{{ __( 'Saved payment methods' ) }}">
            @foreach ( $methods as $method )
                <li class="flex flex-col gap-3 rounded-box border border-base-content/10 p-5" wire:key="paypal-saved-method-{{ $method['id'] }}" data-paypal-saved-method="{{ $method['id'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold">{{ $method['label'] }}</h3>

                        <div class="flex flex-wrap gap-1">
                            @if ( $method['default'] )
                                <x-artisanpack-badge :value="__( 'Default' )" class="badge-primary badge-soft" data-paypal-saved-method-default />
                            @endif

                            @if ( $method['expired'] )
                                <x-artisanpack-badge :value="__( 'Expired' )" class="badge-warning badge-soft text-base-content!" data-paypal-saved-method-expired />
                            @endif
                        </div>
                    </div>

                    @if ( null !== $method['expiry'] )
                        <p class="text-sm text-base-content/70">
                            {{ $method['expired'] ? __( 'Expired :date', [ 'date' => $method['expiry'] ] ) : __( 'Expires :date', [ 'date' => $method['expiry'] ] ) }}
                        </p>
                    @endif

                    <div class="mt-auto flex flex-wrap gap-2">
                        {{-- Each button names its method for screen readers, after the visible word (WCAG 2.5.3). --}}
                        @unless ( $method['default'] )
                            <x-artisanpack-button class="btn-sm" wire:click="makeDefault( {{ $method['id'] }} )" wire:loading.attr="disabled" wire:target="makeDefault" data-paypal-saved-method-make-default="{{ $method['id'] }}">{{ __( 'Make default' ) }}<span class="sr-only">: {{ $method['label'] }}</span></x-artisanpack-button>
                        @endunless

                        <x-artisanpack-button icon="o-trash" class="btn-sm btn-ghost" wire:click="confirmDelete( {{ $method['id'] }} )" data-paypal-saved-method-delete="{{ $method['id'] }}">{{ __( 'Remove' ) }}<span class="sr-only">: {{ $method['label'] }}</span></x-artisanpack-button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <x-artisanpack-modal wire:model="confirmingDelete" :title="__( 'Remove this payment method?' )" data-paypal-saved-method-delete-modal>
        <p>{{ null === $deletingLabel ? __( 'It will be removed from PayPal and from your account.' ) : __( ':method will be removed from PayPal and from your account.', [ 'method' => $deletingLabel ] ) }}</p>

        <x-slot:actions>
            <x-artisanpack-button :label="__( 'Cancel' )" class="btn-ghost" @click="$wire.confirmingDelete = false" />
            <x-artisanpack-button :label="__( 'Remove payment method' )" class="btn-error" wire:click="delete" wire:loading.attr="disabled" wire:target="delete" spinner="delete" data-paypal-saved-method-delete-confirm />
        </x-slot:actions>
    </x-artisanpack-modal>
</div>

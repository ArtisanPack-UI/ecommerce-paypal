{{--
    The admin order panel with PayPal transaction details. See
    Livewire\Admin\PayPalOrderPanel. Renders an empty root for orders not
    paid with PayPal.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
@php
    use ArtisanPackUI\Ecommerce\Support\LocalizedDate;
@endphp
<div data-paypal-order-panel>
    @if ( null !== $details )
        <x-artisanpack-card :title="__( 'PayPal' )" shadow>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="opacity-75">{{ __( 'Mode' ) }}</dt>
                <dd data-paypal-panel-mode="{{ $details['mode'] }}">
                    <x-artisanpack-badge :value="$details['live'] ? __( 'Live' ) : __( 'Sandbox' )" :class="$details['live'] ? 'badge-sm badge-success badge-soft' : 'badge-sm badge-warning badge-soft'" />
                </dd>

                @foreach ( $details['ids'] as $id )
                    <dt class="opacity-75">{{ $id['label'] }}</dt>
                    <dd class="break-all font-mono" data-paypal-panel-id="{{ $id['value'] }}">
                        @if ( null !== $id['url'] )
                            <a href="{{ $id['url'] }}" class="link" target="_blank" rel="noopener noreferrer">
                                {{ $id['value'] }}<span class="sr-only"> {{ __( '(opens PayPal in a new tab)' ) }}</span>
                            </a>
                        @else
                            {{ $id['value'] }}
                        @endif
                    </dd>
                @endforeach

                @if ( null !== $details['payer_email'] )
                    <dt class="opacity-75">{{ __( 'Payer email' ) }}</dt>
                    <dd class="break-all" data-paypal-panel-payer>{{ $details['payer_email'] }}</dd>
                @endif

                @if ( null !== $details['funding_source'] )
                    <dt class="opacity-75">{{ __( 'Funding source' ) }}</dt>
                    <dd data-paypal-panel-funding>{{ $details['funding_source'] }}@if ( null !== $details['card'] ) ({{ $details['card'] }})@endif</dd>
                @endif

                @if ( null !== $details['liability_shift'] )
                    <dt class="opacity-75">{{ __( 'Liability shift' ) }}</dt>
                    <dd data-paypal-panel-liability="{{ $details['liability_shift'] }}">
                        {{ match ( $details['liability_shift'] ) {
                            'POSSIBLE' => __( 'Possible (3-D Secure passed)' ),
                            'YES'      => __( 'Yes' ),
                            'NO'       => __( 'No' ),
                            default    => __( 'Unknown' ),
                        } }}
                    </dd>
                @endif

                @if ( null !== $details['authorization'] )
                    <dt class="opacity-75">{{ __( 'Authorization' ) }}</dt>
                    <dd data-paypal-panel-authorization="{{ $details['authorization']['status'] }}">
                        {{ $details['authorization']['status'] ?? __( 'Unknown' ) }}
                        @if ( null !== $details['authorization']['expires_at'] && in_array( $details['authorization']['status'], [ null, 'CREATED', 'PENDING' ], true ) )
                            <span @class( [ 'block', 'font-semibold' => $details['authorization']['expired'] ] ) data-paypal-panel-expires>
                                {{ $details['authorization']['expired']
                                    ? __( 'Expired :date', [ 'date' => LocalizedDate::format( $details['authorization']['expires_at'] ) ] )
                                    : __( 'Expires :date', [ 'date' => LocalizedDate::format( $details['authorization']['expires_at'] ) ] ) }}
                            </span>
                        @endif
                    </dd>
                @endif

                <dt class="opacity-75">{{ __( 'Disputes' ) }}</dt>
                <dd data-paypal-panel-disputes="{{ count( $details['disputes'] ) }}">
                    @forelse ( $details['disputes'] as $dispute )
                        <div class="flex flex-col gap-0.5" wire:key="paypal-dispute-{{ $dispute['id'] }}" data-paypal-panel-dispute="{{ $dispute['id'] }}">
                            <span>
                                <x-artisanpack-badge :value="str_replace( '_', ' ', $dispute['status'] )" class="badge-sm badge-error badge-soft" />
                                @if ( null !== $dispute['url'] )
                                    <a href="{{ $dispute['url'] }}" class="link font-mono" target="_blank" rel="noopener noreferrer">{{ $dispute['id'] }}<span class="sr-only"> {{ __( '(opens PayPal in a new tab)' ) }}</span></a>
                                @else
                                    <span class="font-mono">{{ $dispute['id'] }}</span>
                                @endif
                            </span>

                            @if ( null !== $dispute['reason'] || null !== $dispute['amount'] )
                                <span class="opacity-75">{{ implode( ' · ', array_filter( [ null === $dispute['reason'] ? null : str_replace( '_', ' ', $dispute['reason'] ), $dispute['amount'] ] ) ) }}</span>
                            @endif

                            @if ( null !== $dispute['outcome'] )
                                <span class="opacity-75">{{ __( 'Outcome: :outcome', [ 'outcome' => str_replace( '_', ' ', $dispute['outcome'] ) ] ) }}</span>
                            @elseif ( null !== $dispute['respond_by'] )
                                <span>{{ __( 'Respond by :date', [ 'date' => LocalizedDate::format( $dispute['respond_by'] ) ] ) }}</span>
                            @endif
                        </div>
                    @empty
                        <span class="opacity-75">{{ __( 'None' ) }}</span>
                    @endforelse
                </dd>
            </dl>
        </x-artisanpack-card>
    @endif
</div>

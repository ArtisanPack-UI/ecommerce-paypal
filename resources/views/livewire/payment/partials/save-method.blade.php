{{--
    "Save for faster checkout" (spec §10.2). Only rendered for a signed-in
    shopper when vaulting is on. Changing it replaces the PayPal Order (see
    PayPalPaymentDriver::updatedSaveMethod()), so it is disabled while that
    runs.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
<div data-paypal-save-method>
    <x-artisanpack-checkbox
        :label="$label"
        :hint="$hint"
        wire:model.live="saveMethod"
        wire:loading.attr="disabled"
        wire:target="saveMethod"
    />
</div>

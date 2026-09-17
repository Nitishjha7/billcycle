<?php

return [

    // Only "fake" is supported. See docs/TECHNICAL_SPEC.md #6 for why there is
    // no real gateway integration.
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    // Drives random failures in FakeGateway for demo purposes only. Tests set
    // gateway behaviour explicitly instead of relying on this.
    'fake_gateway_failure_rate' => (float) env('FAKE_GATEWAY_FAILURE_RATE', 0),

];

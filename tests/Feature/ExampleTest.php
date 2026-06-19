<?php

test('home route is not publicly discoverable', function () {
    $response = $this->get(route('home'));

    $response->assertNotFound();
});

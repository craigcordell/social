<?php

test('registration screen is not publicly available', function () {
    $this->get('/register')->assertNotFound();
});

test('registration endpoint is not publicly available', function () {
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

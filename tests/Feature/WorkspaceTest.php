<?php

it('renders the workspace skeleton', function () {
    $response = $this->get('/');

    $response->assertStatus(200)
        ->assertSee('Connections')
        ->assertSee('No open connections');
});

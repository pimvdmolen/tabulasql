<?php

use App\Livewire\ResultsPanel;
use App\Services\ConnectionManager;
use App\Services\DataEditor;
use App\Services\RelationResolver;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
    $this->db = app(ConnectionManager::class)->db($this->connection, 'demo');

    // Isolated scratch table per test.
    $this->db->statement('DROP TABLE IF EXISTS demo.scratch');
    $this->db->statement('CREATE TABLE demo.scratch (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(50) NOT NULL DEFAULT "fresh",
        qty INT NULL
    )');
    $this->db->statement('INSERT INTO demo.scratch (label, qty) VALUES ("one", 1), ("two", 2)');
});

afterEach(function () {
    $this->db?->statement('DROP TABLE IF EXISTS demo.scratch');
});

it('updates a row by primary key', function () {
    $affected = app(DataEditor::class)->update($this->connection, 'demo', 'scratch', ['id' => 1], ['label' => 'changed', 'qty' => null]);

    expect($affected)->toBe(1);

    $row = $this->db->selectOne('SELECT * FROM demo.scratch WHERE id = 1');
    expect($row->label)->toBe('changed')->and($row->qty)->toBeNull();
});

it('applies the DEFAULT sentinel', function () {
    app(DataEditor::class)->update($this->connection, 'demo', 'scratch', ['id' => 2], ['label' => DataEditor::DEFAULT]);

    expect($this->db->selectOne('SELECT label FROM demo.scratch WHERE id = 2')->label)->toBe('fresh');
});

it('refuses to edit without a primary key', function () {
    app(DataEditor::class)->update($this->connection, 'demo', 'scratch', [], ['label' => 'x']);
})->throws(RuntimeException::class, 'no primary key');

it('inserts, duplicates and deletes rows', function () {
    $editor = app(DataEditor::class);

    $editor->insert($this->connection, 'demo', 'scratch', ['label' => 'three', 'qty' => 3]);
    expect($this->db->selectOne('SELECT COUNT(*) AS n FROM demo.scratch')->n)->toBe(3);

    $editor->duplicate($this->connection, 'demo', 'scratch', ['id' => 1]);
    $rows = $this->db->select('SELECT * FROM demo.scratch WHERE label = "one"');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->id)->not->toBe($rows[1]->id);

    $deleted = $editor->delete($this->connection, 'demo', 'scratch', [['id' => 1], ['id' => 2]]);
    expect($deleted)->toBe(2);
});

it('opens the insert dialog with all non-auto-increment columns and refreshes on save', function () {
    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'scratch')
        ->call('openInsertDialog')
        ->assertSet('showInsertDialog', true)
        ->assertSee('label')
        ->assertSee('qty')
        // The auto-increment id column isn't offered as an input.
        ->assertDontSee('insertValues.id')
        ->set('insertValues.label', 'three')
        ->set('insertValues.qty', '3')
        ->call('saveInsert')
        ->assertSet('showInsertDialog', false)
        // The grid must show the new row immediately, no manual refresh.
        ->assertSee('three');

    expect($this->db->selectOne('SELECT qty FROM demo.scratch WHERE label = "three"')->qty)->toBe(3);
});

it('closes the insert dialog and discards unsaved values', function () {
    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'scratch')
        ->call('openInsertDialog')
        ->set('insertValues.label', 'abandoned')
        ->call('closeInsertDialog')
        ->assertSet('showInsertDialog', false)
        ->assertSet('insertValues', []);

    expect($this->db->selectOne('SELECT COUNT(*) AS n FROM demo.scratch')->n)->toBe(2);
});

it('edits cells through the grid with pending state', function () {
    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'scratch')
        ->call('startEdit', 0, 'label')
        ->assertSet('editingCell', ['row' => 0, 'col' => 'label'])
        ->call('setCellValue', 0, 'label', 'edited')
        ->assertSet('pendingEdits.0.label', 'edited')
        ->call('saveChanges')
        ->assertSet('pendingEdits', [])
        // The grid must show the fresh value immediately, without a manual
        // refresh (the computed result is invalidated after the mutation).
        ->assertSee('edited')
        ->assertDontSee('>one<', false);

    expect($this->db->selectOne('SELECT label FROM demo.scratch WHERE id = 1')->label)->toBe('edited');
});

it('does not treat re-entering the same value as a pending change', function () {
    $this->db->statement('INSERT INTO demo.scratch (label, qty) VALUES ("three", NULL)');

    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'scratch')
        // Same string value.
        ->call('setCellValue', 0, 'label', 'one')
        ->assertSet('pendingEdits', [])
        // Same numeric value, submitted as a string like a form input would.
        ->call('setCellValue', 0, 'qty', '1')
        ->assertSet('pendingEdits', [])
        // An already-NULL cell submitted blank (its displayed value).
        ->call('setCellValue', 2, 'qty', '')
        ->assertSet('pendingEdits', [])
        // A genuine change still registers...
        ->call('setCellValue', 0, 'label', 'changed')
        ->assertSet('pendingEdits.0.label', 'changed')
        // ...and editing it back to the original value clears it again.
        ->call('setCellValue', 0, 'label', 'one')
        ->assertSet('pendingEdits', []);
});

it('marks tables without a primary key read-only', function () {
    $this->db->statement('CREATE TABLE demo.nopk (a INT)');
    $this->db->statement('INSERT INTO demo.nopk VALUES (1)');

    try {
        $component = Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
            ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'nopk');

        expect($component->instance()->isEditable())->toBeFalse();
        $component->assertSee('read-only');
    } finally {
        $this->db->statement('DROP TABLE demo.nopk');
    }
});

it('filters via quick filter and chips', function () {
    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'scratch')
        ->call('quickFilter', 0, 'label', '=')
        ->assertSet('filters.0.operator', '=')
        ->assertSee('one')
        ->assertDontSee('>two<', false)
        ->call('clearFilters')
        ->assertSet('filters', []);
});

it('applies the filter dialog with sql preview', function () {
    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'scratch')
        ->call('openFilterDialog')
        ->set('draftFilters.0.column', 'qty')
        ->set('draftFilters.0.operator', '>=')
        ->set('draftFilters.0.value', '2')
        ->call('applyFilters')
        ->assertSet('showFilterDialog', false)
        ->assertSee('two')
        ->assertDontSee('>one<', false);
});

it('resolves real foreign keys and convention matches', function () {
    $resolver = app(RelationResolver::class);
    $map = $resolver->foreignKeys($this->connection, 'demo', 'orders');

    expect($map['customer_id']['table'])->toBe('customers')
        ->and($map['customer_id']['convention'])->toBeFalse();

    $record = $resolver->related($this->connection, $map['customer_id'], 1);
    expect($record['name'])->toBe('Alice');
});

it('does not match a convention column against its own table', function () {
    // Regression: `google_review_id` on `google_reviews` would otherwise
    // "convention-match" against itself, since the table is literally the
    // singular+id form of its own name.
    $this->db->statement('CREATE TABLE demo.google_reviews (id INT PRIMARY KEY, google_review_id VARCHAR(64))');

    try {
        $map = app(RelationResolver::class)->foreignKeys($this->connection, 'demo', 'google_reviews');

        expect($map)->not->toHaveKey('google_review_id');
    } finally {
        $this->db->statement('DROP TABLE demo.google_reviews');
    }
});

it('still detects a real self-referencing foreign key constraint', function () {
    // The fix only narrows the naming-convention guess; a genuine
    // self-referencing FK constraint (a hierarchy's parent_id) is found via
    // information_schema before the convention loop even runs, so it must
    // keep working exactly as before.
    $this->db->statement('CREATE TABLE demo.categories (
        id INT PRIMARY KEY,
        parent_id INT NULL,
        FOREIGN KEY (parent_id) REFERENCES categories(id)
    )');

    try {
        $map = app(RelationResolver::class)->foreignKeys($this->connection, 'demo', 'categories');

        expect($map['parent_id']['table'])->toBe('categories')
            ->and($map['parent_id']['convention'])->toBeFalse();
    } finally {
        $this->db->statement('DROP TABLE demo.categories');
    }
});

it('drills into related records from the grid', function () {
    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'orders')
        ->call('showRelated', 0, 'customer_id')
        ->assertCount('recordStack', 1)
        ->assertSee('alice@example.com')
        ->call('openRecordInGrid')
        ->assertSet('table', 'customers')
        ->assertSet('filters.0.column', 'id')
        ->assertCount('recordStack', 0);
});

it('copies rows as csv and insert statements', function () {
    $component = Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'scratch');

    expect($component->instance()->copyCell(0, 'label'))->toBe('one')
        ->and($component->instance()->copyRowCsv(0))->toBe('"1","one","1"')
        ->and($component->instance()->copyRowInsert(0))
        ->toBe("INSERT INTO `demo`.`scratch` (`id`, `label`, `qty`) VALUES (1, 'one', 1);");
});

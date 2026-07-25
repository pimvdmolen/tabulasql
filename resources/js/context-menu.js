/**
 * App-wide right-click context menu.
 *
 * Elements open it with:
 *   x-on:contextmenu.prevent="$store.ctx.open($event, items)"
 * where items = [{ label, run?, children?, danger?, divider?, disabled? }].
 * The single menu element lives in the workspace view and renders $store.ctx.
 */
export function registerContextMenu(Alpine) {
    Alpine.store('ctx', {
        visible: false,
        x: 0,
        y: 0,
        items: [],

        open(event, items) {
            this.items = items.filter(Boolean);
            // Clamp to the viewport (menu ~220px wide, ~32px per item).
            const height = this.items.length * 28 + 8;
            this.x = Math.min(event.clientX, window.innerWidth - 240);
            this.y = Math.min(event.clientY, window.innerHeight - height - 8);
            this.visible = true;
        },

        close() {
            this.visible = false;
        },

        run(item) {
            if (item.disabled || item.divider || item.children) return;
            this.close();
            item.run?.();
        },
    });

    const clip = (text) => navigator.clipboard.writeText(String(text ?? ''));

    // ---------------------------------------------------------------
    // Grid cell menu (ResultsPanel $wire)
    window.gridCellMenu = ($wire, p) => [
        ...(p.editable ? [
            { label: 'Insert New Row', run: () => $wire.openInsertDialog() },
            { label: 'Duplicate Current Row', run: () => $wire.duplicateRow(p.row) },
            { label: 'Delete Selected Row(s)', danger: true, disabled: !p.hasSelection, run: () => $wire.confirmDeleteRows() },
            { divider: true },
            { label: 'Save Changes', disabled: !p.hasPending, run: () => $wire.saveChanges() },
            { label: 'Cancel Changes', disabled: !p.hasPending, run: () => $wire.cancelChanges() },
            { divider: true },
            { label: 'Set To NULL', run: () => $wire.setCellSpecial(p.row, p.col, 'null') },
            { label: 'Set To Empty String', run: () => $wire.setCellSpecial(p.row, p.col, 'empty') },
            { label: 'Set To Default', run: () => $wire.setCellSpecial(p.row, p.col, 'default') },
            { divider: true },
        ] : []),
        ...(p.isFk ? [{ label: 'Show Related Record…', run: () => $wire.showRelated(p.row, p.col) }] : []),
        {
            label: 'Filter',
            children: [
                { label: '= this value', run: () => $wire.quickFilter(p.row, p.col, '=') },
                { label: '≠ this value', run: () => $wire.quickFilter(p.row, p.col, '<>') },
                { label: 'LIKE %value%', run: () => $wire.quickFilter(p.row, p.col, 'LIKE') },
                { label: 'Remove all filters', disabled: !p.hasFilters, run: () => $wire.clearFilters() },
            ],
        },
        { label: 'Unsort', disabled: !p.sorted, run: () => $wire.unsort() },
        { divider: true },
        {
            label: 'Copy',
            children: [
                { label: 'Cell value', run: async () => clip(await $wire.copyCell(p.row, p.col)) },
                { label: 'Row as CSV', run: async () => clip(await $wire.copyRowCsv(p.row, ',')) },
                { label: 'Row as tab-separated', run: async () => clip(await $wire.copyRowCsv(p.row, '\t')) },
                { label: 'Row as INSERT', run: async () => clip(await $wire.copyRowInsert(p.row)) },
            ],
        },
        {
            label: 'Export All Rows As',
            children: [
                { label: 'CSV', run: () => $wire.exportRows('csv') },
                { label: 'JSON', run: () => $wire.exportRows('json') },
                { label: 'SQL INSERTs', run: () => $wire.exportRows('sql') },
            ],
        },
    ];

    // ---------------------------------------------------------------
    // Object explorer: table/view menu (ObjectExplorer $wire)
    window.treeTableMenu = ($wire, p) => {
        const isView = p.type === 'view';
        const noun = isView ? 'View' : 'Table';

        return [
            { label: `Open ${noun}`, run: () => $wire.selectTable(p.database, p.table) },
            { label: 'Open in New Query Tab', run: () => window.Livewire.dispatch('open-in-query-tab', {
                connectionId: p.connectionId, database: p.database, table: p.table,
            }) },
            { divider: true },
            { label: `Copy ${noun}(s) To…`, run: () => $wire.openCopyWizard(p.database, p.table, p.type) },
            {
                label: 'Backup/Export',
                children: [
                    { label: 'SQL dump…', run: () => window.Livewire.dispatch('open-export-wizard', {
                        connectionId: p.connectionId, database: p.database, objects: [{ name: p.table, type: p.type }],
                    }) },
                    { label: 'Data as CSV', run: () => window.Livewire.dispatch('export-table-data', {
                        connectionId: p.connectionId, database: p.database, table: p.table, format: 'csv',
                    }) },
                    { label: 'Data as JSON', run: () => window.Livewire.dispatch('export-table-data', {
                        connectionId: p.connectionId, database: p.database, table: p.table, format: 'json',
                    }) },
                ],
            },
            { label: 'Import SQL File…', run: () => window.Livewire.dispatch('open-import-dialog', {
                connectionId: p.connectionId, database: p.database,
            }) },
            ...(isView ? [] : [{
                label: 'Paste SQL Statement',
                children: ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE'].map((kind) => ({
                    label: kind,
                    run: () => window.Livewire.dispatch('paste-sql-template', {
                        connectionId: p.connectionId, database: p.database, table: p.table, kind,
                    }),
                })),
            }]),
            { divider: true },
            { label: `Alter ${noun} (DDL in editor)`, run: () => window.Livewire.dispatch('paste-sql-template', {
                connectionId: p.connectionId, database: p.database, table: p.table, kind: 'CREATE',
            }) },
            ...(isView ? [] : [
                { label: 'Manage Indexes…', run: () => window.Livewire.dispatch('open-index-manager', {
                    connectionId: p.connectionId, database: p.database, table: p.table,
                }) },
                { label: 'Foreign Keys…', run: () => window.Livewire.dispatch('open-fk-manager', {
                    connectionId: p.connectionId, database: p.database, table: p.table,
                }) },
            ]),
            { divider: true },
            // Views can't be renamed or truncated with plain RENAME/TRUNCATE
            // statements the way tables can, so those actions only make
            // sense for tables (matches SQLyog, which greys them out for views).
            ...(isView ? [] : [
                { label: 'Rename Table…', run: () => $wire.startOperation('rename', p.database, p.table) },
                { label: 'Truncate Table…', danger: true, run: () => $wire.startOperation('truncate', p.database, p.table, 'table') },
            ]),
            { label: `Drop ${noun}…`, danger: true, run: () => $wire.startOperation('drop', p.database, p.table, p.type) },
            { divider: true },
            { label: 'Refresh', run: () => $wire.refreshTables(p.database) },
        ];
    };

    // Object explorer: procedure/function/event menu (simple named routines,
    // no owning table). Create/Alter open a query tab with a DDL skeleton or
    // the routine's fetched CREATE statement (same "edit it yourself"
    // convention as "Alter Table (DDL in editor)").
    window.treeRoutineMenu = ($wire, p) => {
        const nouns = { procedure: 'Procedure', function: 'Function', event: 'Event' };
        const noun = nouns[p.kind] ?? p.kind;

        return [
            { label: `Alter ${noun} (DDL in editor)…`, run: () => window.Livewire.dispatch('paste-routine-template', {
                connectionId: p.connectionId, database: p.database, name: p.name, kind: `alter-${p.kind}`,
            }) },
            { divider: true },
            { label: `Drop ${noun}…`, danger: true, run: () => $wire.startOperation('drop', p.database, p.name, p.kind) },
            { divider: true },
            { label: 'Refresh', run: () => $wire.refreshTables(p.database) },
        ];
    };

    // Object explorer: trigger menu (named, but tied to an owning table).
    window.treeTriggerMenu = ($wire, p) => [
        { label: 'Alter Trigger (DDL in editor)…', run: () => window.Livewire.dispatch('paste-routine-template', {
            connectionId: p.connectionId, database: p.database, name: p.name, kind: 'alter-trigger',
        }) },
        { divider: true },
        { label: 'Drop Trigger…', danger: true, run: () => $wire.startOperation('drop', p.database, p.name, 'trigger') },
        { divider: true },
        { label: 'Refresh', run: () => $wire.refreshTables(p.database) },
    ];

    // Object explorer: database menu
    window.treeDatabaseMenu = ($wire, p) => [
        {
            label: 'Create',
            children: [
                { label: 'Table…', run: () => window.Livewire.dispatch('open-create-table', {
                    connectionId: p.connectionId, database: p.database,
                }) },
                { label: 'View…', run: () => window.Livewire.dispatch('paste-routine-template', {
                    connectionId: p.connectionId, database: p.database, kind: 'create-view',
                }) },
                { label: 'Procedure…', run: () => window.Livewire.dispatch('paste-routine-template', {
                    connectionId: p.connectionId, database: p.database, kind: 'create-procedure',
                }) },
                { label: 'Function…', run: () => window.Livewire.dispatch('paste-routine-template', {
                    connectionId: p.connectionId, database: p.database, kind: 'create-function',
                }) },
                { label: 'Trigger…', run: () => window.Livewire.dispatch('paste-routine-template', {
                    connectionId: p.connectionId, database: p.database, kind: 'create-trigger',
                }) },
                { label: 'Event…', run: () => window.Livewire.dispatch('paste-routine-template', {
                    connectionId: p.connectionId, database: p.database, kind: 'create-event',
                }) },
            ],
        },
        { label: 'Copy Tables To…', run: () => $wire.openCopyWizardForDatabase(p.database) },
        { divider: true },
        { label: 'Export Database…', run: () => window.Livewire.dispatch('open-export-wizard', {
            connectionId: p.connectionId, database: p.database,
        }) },
        { label: 'Import SQL File…', run: () => window.Livewire.dispatch('open-import-dialog', {
            connectionId: p.connectionId, database: p.database,
        }) },
        { divider: true },
        { label: 'Refresh', run: () => $wire.refreshTables(p.database) },
        { divider: true },
        {
            label: 'More Database Operations',
            children: [
                { label: 'Truncate Database…', danger: true, run: () => $wire.startOperation('truncate-database', p.database, null) },
                { label: 'Empty Database…', danger: true, run: () => $wire.startOperation('empty-database', p.database, null) },
            ],
        },
        { label: 'Drop Database…', danger: true, run: () => $wire.startOperation('drop-database', p.database, null) },
    ];

    // Connections sidebar: connection menu
    window.treeConnectionMenu = ($wire, p) => [
        {
            label: !p.isOpen
                ? 'Create Database… (connect first)'
                : (p.restricted ? 'Create Database… (connection is restricted to one database)' : 'Create Database…'),
            disabled: !p.isOpen || p.restricted,
            run: () => window.Livewire.dispatch('open-create-database', { connectionId: p.connectionId }),
        },
    ];
}

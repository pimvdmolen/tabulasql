import { basicSetup } from 'codemirror';
import { EditorView, keymap } from '@codemirror/view';
import { Compartment, Prec } from '@codemirror/state';
import { sql, MySQL } from '@codemirror/lang-sql';
import { oneDark } from '@codemirror/theme-one-dark';

const lightTheme = EditorView.theme({
    '&': { backgroundColor: 'transparent' },
});

/**
 * One CodeMirror instance per query tab. Talks to the QueryEditor Livewire
 * component through $wire and window-level events:
 *  - 'sql-run'    {connectionId, tabId, action: 'run'|'explain'} from toolbar buttons
 *  - 'sql-schema' {connectionId, schema} pushes autocomplete tables/columns
 *  - 'sql-insert' {connectionId, tabId, sql} inserts text (query history)
 *  - 'themechange' re-applies the light/dark editor theme
 */
export function registerSqlEditor(Alpine) {
    Alpine.data('sqlEditor', ({ connectionId, tabId, initial = '' }) => ({
        view: null,

        init() {
            this.themeCompartment = new Compartment();
            this.schemaCompartment = new Compartment();

            const isDark = () => document.documentElement.classList.contains('dark');

            const runKeymap = Prec.highest(keymap.of([
                { key: 'Ctrl-Enter', mac: 'Cmd-Enter', run: () => { this.execute('run', false); return true; } },
                { key: 'Ctrl-Shift-Enter', mac: 'Cmd-Shift-Enter', run: () => { this.execute('run', true); return true; } },
            ]));

            let saveTimer = null;

            this.view = new EditorView({
                doc: initial,
                parent: this.$refs.editor,
                extensions: [
                    runKeymap,
                    basicSetup,
                    this.schemaCompartment.of(sql({ dialect: MySQL, upperCaseKeywords: true })),
                    this.themeCompartment.of(isDark() ? oneDark : lightTheme),
                    EditorView.updateListener.of((update) => {
                        if (update.docChanged) {
                            clearTimeout(saveTimer);
                            saveTimer = setTimeout(() => {
                                this.$wire.updateSql(tabId, this.view.state.doc.toString());
                            }, 750);
                        }
                    }),
                ],
            });

            this.onRun = (event) => {
                const { detail } = event;
                if (detail.connectionId === connectionId && detail.tabId === tabId) {
                    this.execute(detail.action, null);
                }
            };

            this.onSchema = (event) => {
                if (event.detail.connectionId !== connectionId) return;
                this.view.dispatch({
                    effects: this.schemaCompartment.reconfigure(
                        sql({ dialect: MySQL, upperCaseKeywords: true, schema: event.detail.schema })
                    ),
                });
            };

            this.onInsert = (event) => {
                const { detail } = event;
                if (detail.connectionId !== connectionId || detail.tabId !== tabId) return;
                const doc = this.view.state.doc;
                const insert = (doc.length > 0 && !doc.toString().endsWith('\n') ? '\n' : '') + detail.sql;
                this.view.dispatch({
                    changes: { from: doc.length, insert },
                    selection: { anchor: doc.length + insert.length },
                    scrollIntoView: true,
                });
                this.view.focus();
            };

            this.onTheme = () => {
                this.view.dispatch({
                    effects: this.themeCompartment.reconfigure(isDark() ? oneDark : lightTheme),
                });
            };

            window.addEventListener('sql-run', this.onRun);
            window.addEventListener('sql-schema', this.onSchema);
            window.addEventListener('sql-insert', this.onInsert);
            window.addEventListener('themechange', this.onTheme);
        },

        /**
         * @param {'run'|'explain'} action
         * @param {?boolean} selectionOnly null = selection if present, else all
         */
        execute(action, selectionOnly) {
            const { state } = this.view;
            const selection = state.sliceDoc(state.selection.main.from, state.selection.main.to);

            if (selectionOnly === true && selection === '') return;

            const text = (selectionOnly === true || (selectionOnly === null && selection !== ''))
                ? selection
                : state.doc.toString();

            if (text.trim() === '') return;

            this.$wire.call(action === 'explain' ? 'explain' : 'run', text);
        },

        destroy() {
            window.removeEventListener('sql-run', this.onRun);
            window.removeEventListener('sql-schema', this.onSchema);
            window.removeEventListener('sql-insert', this.onInsert);
            window.removeEventListener('themechange', this.onTheme);
            this.view?.destroy();
        },
    }));
}

import { registerSqlEditor } from './editor';
import { registerContextMenu } from './context-menu';

const splitterStorageKey = (key) => `tabula:splitter:${key}`;

function readSplitterSize(key, initial, min, max) {
    if (!key) {
        return initial;
    }

    try {
        const raw = localStorage.getItem(splitterStorageKey(key));
        if (raw === null) {
            return initial;
        }

        const value = Number.parseInt(raw, 10);
        if (Number.isNaN(value)) {
            return initial;
        }

        return Math.min(max, Math.max(min, value));
    } catch {
        return initial;
    }
}

function writeSplitterSize(key, size) {
    if (!key) {
        return;
    }

    try {
        localStorage.setItem(splitterStorageKey(key), String(size));
    } catch {
        // Private mode / quota; ignore.
    }
}

// Alpine is bundled and started by Livewire; register components on alpine:init.
document.addEventListener('alpine:init', () => {
    registerSqlEditor(window.Alpine);
    registerContextMenu(window.Alpine);
    // Draggable pane splitter. Usage:
    //   x-data="splitter({ axis: 'x', initial: 240, min: 170, max: 420, key: 'connections' })"
    //   :style="`width: ${size}px`" (or height for axis 'y')
    //   <div x-bind="handle" class="... cursor-col-resize"></div>
    // Optional `key` persists size in localStorage across refreshes.
    window.Alpine.data('splitter', ({ axis = 'x', initial = 240, min = 100, max = 800, key = null } = {}) => ({
        size: readSplitterSize(key, initial, min, max),

        handle: {
            ['@mousedown.prevent'](event) {
                const start = axis === 'x' ? event.clientX : event.clientY;
                const startSize = this.size;

                const onMove = (e) => {
                    const current = axis === 'x' ? e.clientX : e.clientY;
                    this.size = Math.min(max, Math.max(min, startSize + (current - start)));
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    writeSplitterSize(key, this.size);
                };

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
                document.body.style.cursor = axis === 'x' ? 'col-resize' : 'row-resize';
                document.body.style.userSelect = 'none';
            },
        },
    }));

    // Drag-resizable <table> columns. Usage:
    //   <table x-data="resizableColumns()">
    //     <col :style="widths[name] ? `width:${widths[name]}px;min-width:${widths[name]}px` : ''">
    //     <th data-col="name">... resize handle → startResize(...)</th>
    // On first drag we snapshot every column's current width as min-width so
    // widening one column grows the table instead of crushing its neighbours
    // (table-layout:fixed caused that). Autofit-all is only on the checkbox
    // column handle in the blade.
    window.Alpine.data('resizableColumns', () => ({
        widths: {},
        fitted: false,
        // Instant row highlight (no Livewire round-trip).
        focusedRow: null,

        focusRow(index) {
            this.focusedRow = index;
        },

        // Snapshot current column widths so opening a cell editor cannot
        // reflow the table (TablePlus-style: edit scrolls inside the cell).
        freezeAllWidths() {
            if (Object.keys(this.widths).length > 0) {
                return;
            }

            const snapshot = {};
            this.$el.querySelectorAll('thead [data-col]').forEach((cell) => {
                snapshot[cell.dataset.col] = cell.offsetWidth;
            });
            this.widths = snapshot;
            this.fitted = true;
        },

        rowHighlightClass(index, selected) {
            if (this.focusedRow === index) {
                return 'bg-sky-500/20 hover:bg-sky-500/20';
            }

            if (selected) {
                return 'bg-sky-500/10 hover:bg-sky-500/15';
            }

            return 'hover:bg-raised/50';
        },

        startResize(name, event, th) {
            const startX = event.clientX;
            let startWidth = this.widths[name] ?? th.offsetWidth;
            let dragging = false;

            const freezeLayout = () => {
                if (Object.keys(this.widths).length > 0) {
                    startWidth = this.widths[name] ?? startWidth;

                    return;
                }

                const snapshot = {};
                this.$el.querySelectorAll('thead [data-col]').forEach((cell) => {
                    snapshot[cell.dataset.col] = cell.offsetWidth;
                });
                this.widths = snapshot;
                startWidth = snapshot[name] ?? startWidth;
            };

            const onMove = (e) => {
                if (!dragging) {
                    if (Math.abs(e.clientX - startX) < 3) {
                        return;
                    }
                    dragging = true;
                    freezeLayout();
                }

                const next = Math.max(48, startWidth + (e.clientX - startX));
                // Replace the object so Alpine reliably re-renders every <col>.
                this.widths = { ...this.widths, [name]: next };
            };

            const onUp = () => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.body.style.cursor = '';
                document.body.style.userSelect = '';

                // mouseup often lands on the <th> (e.g. after dragging left
                // past the min width), which synthesizes a click → sort.
                // Swallow that one click in the capture phase.
                const suppressClick = (e) => {
                    e.stopImmediatePropagation();
                    e.preventDefault();
                    document.removeEventListener('click', suppressClick, true);
                };
                document.addEventListener('click', suppressClick, true);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
        },

        // Size every column to its widest cell content (header or body).
        // Only wired from the checkbox-column handle; not from data columns.
        autoFitAll() {
            const table = this.$el;
            const sample = table.querySelector('tbody td') ?? table.querySelector('thead [data-col]');
            const cs = getComputedStyle(sample ?? table);

            const probe = document.createElement('span');
            probe.style.cssText = [
                'position: absolute',
                'left: -99999px',
                'top: 0',
                'visibility: hidden',
                'white-space: nowrap',
                `font-family: ${cs.fontFamily}`,
                `font-size: ${cs.fontSize}`,
                `font-weight: ${cs.fontWeight}`,
                `font-style: ${cs.fontStyle}`,
                `letter-spacing: ${cs.letterSpacing}`,
            ].join(';');
            document.body.appendChild(probe);

            const paddingX = (cell) => {
                const s = getComputedStyle(cell);

                return (parseFloat(s.paddingLeft) || 0) + (parseFloat(s.paddingRight) || 0);
            };

            const measure = (text, cell, extra = 0) => {
                probe.textContent = (text || '').replace(/\s+/g, ' ').trim() || ' ';

                return Math.ceil(probe.offsetWidth + paddingX(cell) + extra);
            };

            const next = {};
            const rows = table.querySelectorAll('tbody tr');

            table.querySelectorAll('thead [data-col]').forEach((th) => {
                const colIndex = [...th.parentElement.children].indexOf(th);
                probe.style.fontWeight = getComputedStyle(th).fontWeight;
                let max = measure(th.innerText, th, 14);

                probe.style.fontWeight = cs.fontWeight;
                rows.forEach((tr) => {
                    const td = tr.children[colIndex];
                    if (td) {
                        max = Math.max(max, measure(td.innerText, td, 2));
                    }
                });

                next[th.dataset.col] = Math.max(48, max);
            });

            document.body.removeChild(probe);

            this.widths = next;
            this.fitted = true;
        },
    }));

    // HTML5 drag-and-drop list reorder. Usage:
    //   <div x-data="sortableList({ onReorder: (from, to) => $wire.reorder(from, to) })">
    //     <div draggable="true" x-on:dragstart="dragStart($event, index)" ...>
    window.Alpine.data('sortableList', ({ onReorder = null } = {}) => ({
        dragFrom: null,

        dragStart(event, index) {
            if (event.target.closest('button, a, input, textarea, select, [data-no-drag]')) {
                event.preventDefault();
                this.dragFrom = null;
                return;
            }

            this.dragFrom = index;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(index));
            event.currentTarget.classList.add('opacity-50');
        },

        dragOver(event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        },

        dragEnd(event) {
            event.currentTarget.classList.remove('opacity-50');
            this.dragFrom = null;
        },

        drop(event, toIndex) {
            event.preventDefault();
            const fromIndex = this.dragFrom ?? Number.parseInt(event.dataTransfer.getData('text/plain'), 10);

            if (Number.isNaN(fromIndex) || fromIndex === toIndex || onReorder == null) {
                return;
            }

            onReorder(fromIndex, toIndex);
            this.dragFrom = null;
        },
    }));
});

/**
 * When the user clicks another table while ResultsPanel is still loading,
 * Livewire queues the new request behind the old one by default — so you
 * wait for the slow table even though you already navigated away.
 *
 * Cancel the in-flight ResultsPanel message instead; its onFinish then
 * fires the deferred navigation. The aborted MySQL query may still finish
 * on the server (PDO can't soft-kill mid-query without KILL QUERY), but the
 * UI no longer blocks on it.
 */
document.addEventListener('livewire:init', () => {
    /** @type {Map<string, () => void>} */
    const cancelByComponent = new Map();

    const isResultsPanel = (component) => component?.name === 'results-panel';

    const isTableNavigation = (action) => {
        if ([
            'showTable', 'refresh', 'nextPage', 'previousPage', 'sortBy',
            'unsort', 'applyFilters', 'clearFilters', 'removeFilter', 'quickFilter',
        ].includes(action.name)) {
            return true;
        }

        if (action.name === '__dispatch') {
            const event = action.params?.[0];

            return event === 'table-selected' || event === 'query-result';
        }

        return false;
    };

    Livewire.interceptMessage(({ message, cancel, onFinish }) => {
        if (! isResultsPanel(message.component)) {
            return;
        }

        const id = message.component.id;
        cancelByComponent.set(id, cancel);

        onFinish(() => {
            if (cancelByComponent.get(id) === cancel) {
                cancelByComponent.delete(id);
            }
        });
    });

    // Register after Livewire's built-in queueing interceptor so we see
    // deferred actions and can cancel whatever is blocking them.
    Livewire.interceptAction(({ action }) => {
        if (! isResultsPanel(action.component) || ! isTableNavigation(action)) {
            return;
        }

        if (! action.isDeferred()) {
            return;
        }

        const cancelPrev = cancelByComponent.get(action.component.id);

        if (cancelPrev) {
            cancelPrev();
        }
    });
});

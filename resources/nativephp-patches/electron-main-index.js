import NativePHP from '#plugin';
import { app, shell } from 'electron';
import path from 'path';

// Inherit User's PATH in Process & ChildProcess
import fixPath from 'fix-path';
fixPath();

// Some Linux setups (headless servers, VMs, certain sandboxed/containerized
// dev environments) can't spawn Chromium's separate GPU process at all,
// which crashes the whole app ("GPU process isn't usable. Goodbye.") a few
// seconds in, right when the network service restarts and a fresh GPU
// process attempt follows it. disableHardwareAcceleration() alone isn't
// enough here, Chromium still tries to spawn that process; --in-process-gpu
// avoids spawning it as a separate process at all. Tabula is a simple CRUD
// UI with no need for GPU compositing, so software rendering costs nothing
// noticeable. Must run before app.whenReady() (i.e. before
// NativePHP.bootstrap()).
app.disableHardwareAcceleration();
app.commandLine.appendSwitch('disable-gpu');
app.commandLine.appendSwitch('disable-gpu-compositing');
app.commandLine.appendSwitch('in-process-gpu');

// Any link with target="_blank" (or a window.open() call) would otherwise
// open in a brand new Electron BrowserWindow, complete with its own
// title/menu bar — confusing for what's meant to be an external web link
// (e.g. the "buy me a coffee" link). Catch every webContents the app ever
// creates and send external navigations to the OS's default browser
// instead. Must be registered before NativePHP.bootstrap() creates the
// first window.
app.on('web-contents-created', (_event, contents) => {
    contents.setWindowOpenHandler(({ url }) => {
        shell.openExternal(url);

        return { action: 'deny' };
    });
});

const buildPath = path.resolve(import.meta.dirname, import.meta.env.MAIN_VITE_NATIVEPHP_BUILD_PATH);
const defaultIcon = path.join(buildPath, 'icon.png');
const certificate = path.join(buildPath, 'cacert.pem');

const executable = process.platform === 'win32' ? 'php.exe' : 'php';
const phpBinary = path.join(buildPath, 'php', executable);
const appPath = path.join(buildPath, 'app');

/**
 * Turn on the lights for the NativePHP app.
 */
NativePHP.bootstrap(app, defaultIcon, phpBinary, certificate, appPath);

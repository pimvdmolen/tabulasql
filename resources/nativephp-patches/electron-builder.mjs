import { execFileSync } from 'child_process';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const electronDir = dirname(fileURLToPath(import.meta.url));

const appUrl = process.env.APP_URL;
const appId = process.env.NATIVEPHP_APP_ID;
const appName = process.env.NATIVEPHP_APP_NAME;
const isBuilding = process.env.NATIVEPHP_BUILDING;
const appAuthor = process.env.NATIVEPHP_APP_AUTHOR;
const fileName = process.env.NATIVEPHP_APP_FILENAME;
const appVersion = process.env.NATIVEPHP_APP_VERSION;
const appCopyright = process.env.NATIVEPHP_APP_COPYRIGHT;
const deepLinkProtocol = process.env.NATIVEPHP_DEEPLINK_SCHEME;
const updaterEnabled = process.env.NATIVEPHP_UPDATER_ENABLED === 'true';
const deleteAppDataOnUninstall = process.env.NATIVEPHP_NSIS_DELETE_APP_DATA === 'true';

// Azure signing configuration
const azureEndpoint = process.env.NATIVEPHP_AZURE_ENDPOINT;
const azureCertificateProfileName = process.env.NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME;
const azureCodeSigningAccountName = process.env.NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME;

// Since we do not copy the php executable here, we only need these for building
const isWindows = process.argv.includes('--win');
const isLinux = process.argv.includes('--linux');
const isDarwin = process.argv.includes('--mac');

let targetOs;

if (isWindows) {
    targetOs = 'win';
}

if (isLinux) {
    targetOs = 'linux';
}

if (isDarwin) {
    targetOs = 'mac';
}

let updaterConfig = {};

try {
    updaterConfig = process.env.NATIVEPHP_UPDATER_CONFIG;
    updaterConfig = JSON.parse(updaterConfig);
} catch {
    updaterConfig = {};
}

if (isBuilding) {
    console.log('  • updater config', updaterConfig);
}

export default {
    appId: appId,
    productName: appName,
    copyright: appCopyright,
    directories: {
        buildResources: 'build',
        output: isBuilding ? join(process.env.APP_PATH, 'nativephp', 'electron', 'dist') : undefined,
    },
    files: [
        '!**/.vscode/*',
        '!src/*',
        '!dist/*',
        '!electron.vite.config.{js,ts,mjs,cjs}',
        '!{.eslintignore,.eslintrc.cjs,.prettierignore,.prettierrc.yaml,dev-app-update.yml,CHANGELOG.md,README.md}',
        '!{.env,.env.*,.npmrc,pnpm-lock.yaml}',
    ],
    beforePack: async (context) => {
        let arch = {
            1: 'x64',
            3: 'arm64',
        }[context.arch];

        if (arch === undefined) {
            console.error('Cannot build PHP for unsupported architecture');
            process.exit(1);
        }

        // Must be synchronous: the stock NativePHP hook used fire-and-forget
        // `exec()`, so electron-builder often packed extraResources before
        // php.js finished (or never ran it: wrong cwd). Linux AppImage/.deb
        // then shipped without resources/build/php/php and crashed on launch.
        // Resolve php.js from this config file's directory — electron-builder's
        // process.cwd() is often the Laravel app root, not the electron package.
        const phpJs = join(electronDir, 'php.js');
        console.log(`  • building php binary - node ${phpJs} --${targetOs} --${arch}`);
        execFileSync(process.execPath, [phpJs, `--${targetOs}`, `--${arch}`], {
            stdio: 'inherit',
            env: process.env,
            cwd: electronDir,
        });
    },
    afterSign: 'build/notarize.js',
    win: {
        executableName: fileName,
        ...(azureEndpoint && azureCertificateProfileName && azureCodeSigningAccountName
            ? {
                  azureSignOptions: {
                      endpoint: azureEndpoint,
                      certificateProfileName: azureCertificateProfileName,
                      codeSigningAccountName: azureCodeSigningAccountName,
                  },
              }
            : {}),
    },
    nsis: {
        artifactName: appName + '-${version}-setup.${ext}',
        shortcutName: '${productName}',
        uninstallDisplayName: '${productName}',
        createDesktopShortcut: 'always',
        deleteAppDataOnUninstall: deleteAppDataOnUninstall,
    },
    protocols: {
        name: deepLinkProtocol,
        schemes: [deepLinkProtocol],
    },
    mac: {
        entitlementsInherit: 'build/entitlements.mac.plist',
        artifactName: appName + '-${version}-${arch}.${ext}',
        extendInfo: {
            NSCameraUsageDescription: "Application requests access to the device's camera.",
            NSMicrophoneUsageDescription: "Application requests access to the device's microphone.",
            NSDocumentsFolderUsageDescription: "Application requests access to the user's Documents folder.",
            NSDownloadsFolderUsageDescription: "Application requests access to the user's Downloads folder.",
        },
    },
    dmg: {
        artifactName: appName + '-${version}-${arch}.${ext}',
    },
    linux: {
        target: ['AppImage', 'deb'],
        maintainer: appUrl,
        category: 'Utility',
    },
    appImage: {
        artifactName: appName + '-${version}.${ext}',
    },
    npmRebuild: false,
    extraMetadata: {
        name: fileName,
        homepage: appUrl,
        version: appVersion,
        author: appAuthor,
    },
    extraResources: [
        {
            from: process.env.NATIVEPHP_BUILD_PATH,
            to: 'build',
            filter: ['**/*', '!{.git}'],
        },
    ],
    extraFiles: [
        {
            from: join(process.env.APP_PATH, 'extras'),
            to: 'extras',
            filter: ['**/*'],
        },
    ],
    ...(updaterEnabled ? { publish: updaterConfig } : {}),
};

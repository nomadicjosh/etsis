'use strict';
module.exports = async function installAudio(page, options = {}) {
    await page.addInitScript(({ blocked, delayed }) => {
        let factory;
        Object.defineProperty(window, 'createWorkspaceNotifications', { configurable: true,
            get: () => factory, set: value => { factory = ui => {
                const api = value(ui); window.testNotifications = api; return api;
            }; } });
        window.testTones = 0; window.testAudioContexts = 0; window.testClosedAudio = 0;
        window.testResumeCallbacks = []; window.testDecoded = 0; window.testFileSounds = 0; window.testStoppedFiles = 0;
        window.AudioContext = class {
            constructor() { this.state = 'suspended'; this.currentTime = 0; ++window.testAudioContexts; }
            resume() {
                if (blocked) return Promise.reject(new Error('Fictional browser sound restriction'));
                if (delayed) return new Promise(resolve => window.testResumeCallbacks.push(() => {
                    this.state = 'running'; resolve();
                }));
                this.state = 'running'; return Promise.resolve();
            }
            close() { this.state = 'closed'; ++window.testClosedAudio; return Promise.resolve(); }
            createOscillator() { return { frequency: { setValueAtTime() {} }, connect() {}, disconnect() {},
                start() { ++window.testTones; }, stop() { this.onended?.(); } }; }
            decodeAudioData() { ++window.testDecoded; return Promise.resolve({ duration: 5 }); }
            createBufferSource() {
                let timer;
                return { connect() {}, disconnect() {}, start() { ++window.testTones; ++window.testFileSounds; },
                    stop(at) {
                        if (at !== undefined) timer = setTimeout(() => this.onended?.(), at * 1000);
                        else { clearTimeout(timer); ++window.testStoppedFiles; this.onended?.(); }
                    } };
            }
            createGain() { return { gain: { setValueAtTime() {}, linearRampToValueAtTime() {},
                exponentialRampToValueAtTime() {} }, connect() {}, disconnect() {} }; }
        };
    }, { blocked: !!options.blocked, delayed: !!options.delayed });
};

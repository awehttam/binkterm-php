import { loadMod } from './loader.js';

const WORKLET_URL = '/js/mod-player/mod-player-worklet.js';

const AUDIO = Symbol('audio');
const GAIN = Symbol('gain');
const WORKLET = Symbol('worklet');
const ROW_CALLBACKS = Symbol('rowCallbacks');
const SINGLE_CALLBACKS = Symbol('singleCallbacks');
const STOP_CALLBACKS = Symbol('stopCallbacks');
const ALL_NOTES_CALLBACKS = Symbol('allNotesCallbacks');

const range = function* (min, max) {
    for (let i = min; i <= max; ++i) yield i;
};
const map = function* (iterator, func) {
    for (let i of iterator) yield func(i);
};

const notePerPeriod = [...map(range(0, 65535), p =>
    p < 124 ? null :
    24 + Math.round(12 * Math.log2(428 / p))
)];

export class ModPlayer {
    constructor(audioContext) {
        this.mod = null;
        this.playing = false;
        this[AUDIO] = audioContext || null;
        this[GAIN] = null;
        this[WORKLET] = null;
        this[ROW_CALLBACKS] = [];
        this[SINGLE_CALLBACKS] = {};
        this[STOP_CALLBACKS] = [];
        this[ALL_NOTES_CALLBACKS] = [];
    }

    async load(url) {
        if (this[WORKLET]) this.unload();
        this.mod = await loadMod(url);
        if (!this[AUDIO]) this[AUDIO] = new AudioContext();
        this[GAIN] = this[AUDIO].createGain();
        this[GAIN].gain.value = 0.3;
        await this[AUDIO].audioWorklet.addModule(WORKLET_URL);
        this[WORKLET] = new AudioWorkletNode(this[AUDIO], 'mod-player-worklet');
        this[WORKLET].connect(this[GAIN]).connect(this[AUDIO].destination);
        this[WORKLET].port.onmessage = this.onmessage.bind(this);
    }

    onmessage(event) {
        const { data } = event;
        switch (data.type) {
            case 'row':
                for (let cb of this[ROW_CALLBACKS]) cb(data.position, data.rowIndex);
                const key = data.position + ':' + data.rowIndex;
                if (key in this[SINGLE_CALLBACKS]) {
                    for (let cb of this[SINGLE_CALLBACKS][key]) cb(data.position, data.rowIndex);
                }
                break;
            case 'stop':
                for (let cb of this[STOP_CALLBACKS]) cb();
                break;
            case 'note':
                for (let cb of this[ALL_NOTES_CALLBACKS]) {
                    cb({ channel: data.channel, sample: data.sample, volume: data.volume, note: notePerPeriod[data.period] });
                }
                break;
        }
    }

    watchRows(callback) {
        this[WORKLET].port.postMessage({ type: 'enableRowSubscription' });
        this[ROW_CALLBACKS].push(callback);
    }

    watch(position, row, callback) {
        this[WORKLET].port.postMessage({ type: 'enableRowSubscription' });
        const key = position + ':' + row;
        if (!(key in this[SINGLE_CALLBACKS])) this[SINGLE_CALLBACKS][key] = [];
        this[SINGLE_CALLBACKS][key].push(callback);
    }

    watchStop(callback) {
        this[WORKLET].port.postMessage({ type: 'enableStopSubscription' });
        this[STOP_CALLBACKS].push(callback);
    }

    watchNotes(callback) {
        this[WORKLET].port.postMessage({ type: 'enableNoteSubscription' });
        this[ALL_NOTES_CALLBACKS].push(callback);
    }

    unload() {
        if (this.playing) this.stop();
        if (!this[WORKLET]) return;
        this[WORKLET].disconnect();
        this[AUDIO].close();
        this.mod = null;
        this[AUDIO] = null;
        this[WORKLET] = null;
        this[ROW_CALLBACKS] = [];
        this[SINGLE_CALLBACKS] = {};
        this[ALL_NOTES_CALLBACKS] = [];
    }

    play() {
        if (this.playing || !this[WORKLET]) return;
        this[AUDIO].resume();
        this[WORKLET].port.postMessage({ type: 'play', mod: this.mod, sampleRate: this[AUDIO].sampleRate });
        this.playing = true;
    }

    stop() {
        if (!this.playing) return;
        this[WORKLET].port.postMessage({ type: 'stop' });
        this.playing = false;
    }

    resume() {
        if (this.playing) return;
        this[WORKLET].port.postMessage({ type: 'resume' });
        this.playing = true;
    }

    setRow(position, row) {
        this[WORKLET].port.postMessage({ type: 'setRow', position, row });
    }

    setVolume(volume) {
        this[GAIN].gain.value = volume;
    }
}

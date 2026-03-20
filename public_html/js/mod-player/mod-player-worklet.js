const PAULA_FREQUENCY = 3546894.6;

const ARPEGGIO = 0x00;
const SLIDE_UP = 0x01;
const SLIDE_DOWN = 0x02;
const TONE_PORTAMENTO = 0x03;
const VIBRATO = 0x04;
const TONE_PORTAMENTO_WITH_VOLUME_SLIDE = 0x05;
const VIBRATO_WITH_VOLUME_SLIDE = 0x06;
const SAMPLE_OFFSET = 0x09;
const VOLUME_SLIDE = 0x0A;
const SET_VOLUME = 0x0C;
const PATTERN_BREAK = 0x0D;
const EXTENDED = 0x0e;
const SET_SPEED = 0x0f;
const PORTA_UP_FINE = 0xe1;
const PORTA_DOWN_FINE = 0xe2;
const RETRIGGER_NOTE = 0xe9;
const VOLUME_SLIDE_UP_FINE = 0xea;
const VOLUME_SLIDE_DOWN_FINE = 0xeb;
const DELAY_NOTE = 0xed;

const unimplementedEffects = new Set();

class Channel {
    constructor(worklet, index) {
        this.worklet = worklet;
        this.channelIndex = index;
        this.instrument = null;
        this.playing = false;
        this.period = 0;
        this.currentPeriod = 0;
        this.portamentoSpeed = 0;
        this.periodDelta = 0;
        this.vibratoDepth = 0;
        this.vibratoSpeed = 0;
        this.vibratoIndex = 0;
        this.arpeggio = false;
        this.sampleSpeed = 0.0;
        this.sampleIndex = 0;
        this.volume = 64;
        this.currentVolume = 64;
    }

    nextOutput() {
        if (!this.instrument || !this.period) return 0.0;
        const sample = this.instrument.bytes[this.sampleIndex | 0];
        this.sampleIndex += this.sampleSpeed;
        if (this.instrument.isLooped) {
            if (this.sampleIndex >= this.instrument.repeatOffset + this.instrument.repeatLength) {
                this.sampleIndex = this.instrument.repeatOffset;
            }
        } else if (this.sampleIndex >= this.instrument.length) {
            return 0.0;
        }
        return sample / 256.0 * this.currentVolume / 64;
    }

    performTick() {
        if (this.volumeSlide && this.worklet.tick > 0) {
            this.currentVolume += this.volumeSlide;
            if (this.currentVolume < 0) this.currentVolume = 0;
            if (this.currentVolume > 64) this.currentVolume = 64;
        }
        if (this.vibrato) {
            this.vibratoIndex = (this.vibratoIndex + this.vibratoSpeed) % 64;
            this.currentPeriod = this.period + Math.sin(this.vibratoIndex / 64 * Math.PI * 2) * this.vibratoDepth;
        } else if (this.periodDelta) {
            if (this.portamento) {
                if (this.currentPeriod != this.period) {
                    const sign = Math.sign(this.period - this.currentPeriod);
                    const distance = Math.abs(this.currentPeriod - this.period);
                    const diff = Math.min(distance, this.periodDelta);
                    this.currentPeriod += sign * diff;
                }
            } else {
                this.currentPeriod += this.periodDelta;
            }
        } else if (this.arpeggio) {
            const index = this.worklet.tick % this.arpeggio.length;
            const halfNotes = this.arpeggio[index];
            this.currentPeriod = this.period / Math.pow(2, halfNotes / 12);
        } else if (this.retrigger && (this.worklet.tick % this.retrigger) == 0) {
            this.sampleIndex = 0;
        } else if (this.delayNote === this.worklet.tick) {
            this.instrument = this.setInstrument;
            this.volume = this.setVolume;
            this.currentVolume = this.volume;
            this.period = this.setPeriod;
            this.currentPeriod = this.period;
            this.sampleIndex = 0;
        }
        if (this.currentPeriod < 113) this.currentPeriod = 113;
        if (this.currentPeriod > 856) this.currentPeriod = 856;
        const sampleRate = PAULA_FREQUENCY / this.currentPeriod;
        this.sampleSpeed = sampleRate / this.worklet.sampleRate;
    }

    play(note) {
        let publishNote = false;
        this.setInstrument = false;
        this.setVolume = false;
        this.setPeriod = false;
        this.delayNote = false;
        if (note.instrument) {
            this.setInstrument = this.worklet.mod.instruments[note.instrument];
            this.setVolume = this.setInstrument.volume;
        }
        this.setSampleIndex = false;
        this.setCurrentPeriod = false;
        if (note.period) {
            const instrument = this.setInstrument || this.instrument;
            const finetune = instrument && instrument.finetune || 0;
            this.setPeriod = note.period - finetune;
            this.setCurrentPeriod = true;
            this.setSampleIndex = 0;
            publishNote = true;
        }
        this.effect(note);
        if (this.delayNote) return;
        if (this.setInstrument) this.instrument = this.setInstrument;
        if (this.setVolume !== false) {
            this.volume = this.setVolume;
            this.currentVolume = this.volume;
        }
        if (this.setPeriod) this.period = this.setPeriod;
        if (this.setCurrentPeriod) this.currentPeriod = this.period;
        if (this.setSampleIndex !== false) this.sampleIndex = this.setSampleIndex;
        if (this.worklet.publishNote && publishNote) {
            this.worklet.port.postMessage({
                type: 'note',
                channel: this.channelIndex,
                sample: this.instrument?.index,
                volume: this.currentVolume,
                period: this.period
            });
        }
    }

    effect({hasEffect, effectId, effectData, effectHigh, effectLow}) {
        this.volumeSlide = 0;
        this.periodDelta = 0;
        this.portamento = false;
        this.vibrato = false;
        this.arpeggio = false;
        this.retrigger = false;
        this.delayNote = false;
        if (!hasEffect) return;
        switch (effectId) {
            case ARPEGGIO:
                this.arpeggio = [0, effectHigh, effectLow];
                break;
            case SLIDE_UP:
                this.periodDelta = -effectData;
                break;
            case SLIDE_DOWN:
                this.periodDelta = effectData;
                break;
            case TONE_PORTAMENTO:
                this.portamento = true;
                if (effectData) this.portamentoSpeed = effectData;
                this.periodDelta = this.portamentoSpeed;
                this.setCurrentPeriod = false;
                this.setSampleIndex = false;
                break;
            case PORTA_UP_FINE:
                this.setPeriod = this.period - effectData;
                break;
            case PORTA_DOWN_FINE:
                this.setPeriod = this.period + effectData;
                break;
            case VIBRATO:
                if (effectHigh) this.vibratoSpeed = effectHigh;
                if (effectLow) this.vibratoDepth = effectLow;
                this.vibrato = true;
                break;
            case TONE_PORTAMENTO_WITH_VOLUME_SLIDE:
                this.portamento = true;
                this.setCurrentPeriod = false;
                this.setSampleIndex = false;
                this.periodDelta = this.portamentoSpeed;
                if (effectHigh) this.volumeSlide = effectHigh;
                else if (effectLow) this.volumeSlide = -effectLow;
                break;
            case VIBRATO_WITH_VOLUME_SLIDE:
                this.vibrato = true;
                if (effectHigh) this.volumeSlide = effectHigh;
                else if (effectLow) this.volumeSlide = -effectLow;
                break;
            case VOLUME_SLIDE:
                if (effectHigh) this.volumeSlide = effectHigh;
                else if (effectLow) this.volumeSlide = -effectLow;
                break;
            case VOLUME_SLIDE_UP_FINE:
                this.setVolume = Math.min(64, this.volume + effectData);
                break;
            case VOLUME_SLIDE_DOWN_FINE:
                this.setVolume = Math.max(0, this.volume - effectData);
                break;
            case SAMPLE_OFFSET:
                this.setSampleIndex = effectData * 256;
                break;
            case SET_VOLUME:
                this.setVolume = effectData;
                break;
            case PATTERN_BREAK:
                const row = effectHigh * 10 + effectLow;
                this.worklet.setPatternBreak(row);
                break;
            case SET_SPEED:
                if (effectData >= 1 && effectData <= 31) {
                    this.worklet.setTicksPerRow(effectData);
                } else {
                    this.worklet.setBpm(effectData);
                }
                break;
            case RETRIGGER_NOTE:
                this.retrigger = effectData;
                break;
            case DELAY_NOTE:
                this.delayNote = effectData;
                break;
            default:
                if (!unimplementedEffects.has(effectId)) {
                    unimplementedEffects.add(effectId);
                    console.log(`Unimplemented effect ${effectId.toString(16)}`);
                }
                break;
        }
    }
}

class ModPlayerWorklet extends AudioWorkletProcessor {
    constructor() {
        super();
        this.port.onmessage = this.onmessage.bind(this);
        this.mod = null;
        this.channels = [new Channel(this, 1), new Channel(this, 2), new Channel(this, 3), new Channel(this, 4)];
        this.patternBreak = false;
        this.publishRow = false;
        this.publishStop = false;
        this.publishNote = false;
    }

    onmessage(e) {
        switch (e.data.type) {
            case 'play':    this.play(e.data.mod, e.data.sampleRate); break;
            case 'stop':    this.stop(); break;
            case 'resume':  this.resume(); break;
            case 'setRow':  this.setRow(e.data.position, e.data.row); break;
            case 'enableRowSubscription':   this.publishRow = true; break;
            case 'disableRowSubscription':  this.publishRow = false; break;
            case 'enableStopSubscription':  this.publishStop = true; break;
            case 'enableNoteSubscription':  this.publishNote = true; break;
        }
    }

    play(mod, sampleRate) {
        this.mod = mod;
        this.sampleRate = sampleRate;
        this.setBpm(125);
        this.setTicksPerRow(6);
        this.position = -1;
        this.rowIndex = 63;
        this.tick = 5;
        this.ticksPerRow = 6;
        this.outputsUntilNextTick = 0;
        this.playing = true;
    }

    stop() { this.playing = false; }
    resume() { this.playing = true; }

    setRow(position, row) {
        this.rowIndex = row - 1;
        if (this.rowIndex == -1) {
            this.rowIndex = 63;
            this.position = position - 1;
        } else {
            this.position = position;
        }
        this.tick = this.ticksPerRow - 1;
        this.outputsUntilNextTick = 0;
        this.patternBreak = false;
    }

    setTicksPerRow(ticksPerRow) { this.ticksPerRow = ticksPerRow; }

    setBpm(bpm) {
        this.bpm = bpm;
        this.outputsPerTick = this.sampleRate * 60 / this.bpm / 4 / 6;
        if (bpm === 0 && this.publishStop) {
            this.port.postMessage({ type: 'stop' });
        }
    }

    setPatternBreak(row) { this.patternBreak = row; }

    nextRow() {
        ++this.rowIndex;
        if (this.patternBreak !== false) {
            this.rowIndex = this.patternBreak;
            ++this.position;
            this.patternBreak = false;
        } else if (this.rowIndex == 64) {
            this.rowIndex = 0;
            ++this.position;
        }
        if (this.position >= this.mod.length) this.position = 0;
        const patternIndex = this.mod.patternTable[this.position];
        const pattern = this.mod.patterns[patternIndex];
        const row = pattern.rows[this.rowIndex];
        for (let i = 0; i < 4; ++i) this.channels[i].play(row.notes[i]);
        if (this.publishRow) {
            this.port.postMessage({ type: 'row', position: this.position, rowIndex: this.rowIndex });
        }
    }

    nextTick() {
        ++this.tick;
        if (this.tick == this.ticksPerRow) {
            this.tick = 0;
            this.nextRow();
        }
        for (let i = 0; i < 4; ++i) this.channels[i].performTick();
    }

    nextOutput() {
        if (!this.mod || !this.playing) return 0.0;
        if (this.outputsUntilNextTick <= 0) {
            this.nextTick();
            this.outputsUntilNextTick += this.outputsPerTick;
        }
        this.outputsUntilNextTick--;
        const rawOutput = this.channels.reduce((acc, ch) => acc + ch.nextOutput(), 0.0);
        return Math.tanh(rawOutput);
    }

    process(inputs, outputs) {
        const output = outputs[0];
        const channel = output[0];
        for (let i = 0; i < channel.length; ++i) channel[i] = this.nextOutput();
        return true;
    }
}

registerProcessor('mod-player-worklet', ModPlayerWorklet);

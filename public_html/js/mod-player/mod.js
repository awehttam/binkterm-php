const SUPPORTED_SIGNATURES = new Set(['M.K.', 'M!K!', 'FLT4', '4CHN']);

function readAscii(modfile, offset, length) {
    return String.fromCharCode(...new Uint8Array(modfile, offset, length));
}

function assertRange(modfile, offset, length, message) {
    if (offset < 0 || length < 0 || offset + length > modfile.byteLength) {
        throw new Error(message);
    }
}

class Instrument {
    constructor(modfile, index, sampleStart) {
        assertRange(modfile, 20 + index * 30, 30, `MOD instrument header ${index + 1} is out of bounds`);
        const data = new Uint8Array(modfile, 20 + index * 30, 30);
        const nameBytes = data.slice(0, 21).filter(a => !!a);
        this.index = index;
        this.name = String.fromCodePoint(...nameBytes).trim();
        this.length = 2 * (data[22] * 256 + data[23]);
        this.finetune = data[24];
        if (this.finetune > 7) this.finetune -= 16;
        this.volume = data[25];
        this.repeatOffset = 2 * (data[26] * 256 + data[27]);
        this.repeatLength = 2 * (data[28] * 256 + data[29]);
        assertRange(modfile, sampleStart, this.length,
            `MOD sample ${index + 1} overruns file: offset ${sampleStart}, length ${this.length}, file size ${modfile.byteLength}`);
        this.bytes = new Int8Array(modfile, sampleStart, this.length);
        this.isLooped = this.repeatOffset != 0 || this.repeatLength > 2;
    }
}

class Note {
    constructor (noteData) {
        this.instrument = (noteData[0] & 0xf0) | (noteData[2] >> 4);
        this.period = (noteData[0] & 0x0f) * 256 + noteData[1];
        let effectId = noteData[2] & 0x0f;
        let effectData = noteData[3];
        if (effectId === 0x0e) {
            effectId = 0xe0 | (effectData >> 4);
            effectData &= 0x0f;
        }
        this.rawEffect = ((noteData[2] & 0x0f) << 8) | noteData[3];
        this.effectId = effectId;
        this.effectData = effectData;
        this.effectHigh = effectData >> 4;
        this.effectLow = effectData & 0x0f;
        this.hasEffect = effectId || effectData;
    }
}

class Row {
    constructor(rowData) {
        this.notes = [];
        for (let i = 0; i < 16; i += 4) {
            const noteData = rowData.slice(i, i + 4);
            this.notes.push(new Note(noteData));
        }
    }
}

class Pattern {
    constructor(modfile, index) {
        assertRange(modfile, 1084 + index * 1024, 1024, `MOD pattern ${index} is out of bounds`);
        const data = new Uint8Array(modfile, 1084 + index * 1024, 1024);
        this.rows = [];
        for (let i = 0; i < 64; ++i) {
            const rowData = data.slice(i * 16, i * 16 + 16);
            this.rows.push(new Row(rowData));
        }
    }
}

export class Mod {
    constructor(modfile) {
        if (!(modfile instanceof ArrayBuffer)) {
            throw new Error('MOD loader expected an ArrayBuffer');
        }
        if (modfile.byteLength < 1084) {
            throw new Error(`MOD file too small: ${modfile.byteLength} bytes`);
        }

        const nameArray = new Uint8Array(modfile, 0, 20);
        const nameBytes = nameArray.filter(a => !!a);
        this.name = String.fromCodePoint(...nameBytes).trim();
        this.signature = readAscii(modfile, 1080, 4);
        if (!SUPPORTED_SIGNATURES.has(this.signature)) {
            throw new Error(`Unsupported MOD signature "${this.signature}"`);
        }

        this.length = new Uint8Array(modfile, 950, 1)[0];
        if (this.length < 1 || this.length > 128) {
            throw new Error(`Invalid MOD song length ${this.length}`);
        }
        this.patternTable = new Uint8Array(modfile, 952, this.length);
        const maxPatternIndex = Math.max(...this.patternTable);
        if (!Number.isFinite(maxPatternIndex)) {
            throw new Error('MOD pattern table is empty');
        }

        const minimumSampleStart = 1084 + (maxPatternIndex + 1) * 1024;
        if (minimumSampleStart > modfile.byteLength) {
            throw new Error(
                `MOD patterns overrun file: need ${minimumSampleStart} bytes before samples, got ${modfile.byteLength}`
            );
        }

        this.instruments = [null];
        let sampleStart = minimumSampleStart;
        for (let i = 0; i < 31; ++i) {
            const instr = new Instrument(modfile, i, sampleStart);
            this.instruments.push(instr);
            sampleStart += instr.length;
        }

        this.totalSampleBytes = sampleStart - minimumSampleStart;
        this.expectedSize = sampleStart;
        if (this.expectedSize > modfile.byteLength) {
            throw new Error(`MOD sample data overruns file: need ${this.expectedSize} bytes, got ${modfile.byteLength}`);
        }

        this.patterns = [];
        for (let i = 0; i <= maxPatternIndex; ++i) {
            const pattern = new Pattern(modfile, i);
            this.patterns.push(pattern);
        }
    }
}

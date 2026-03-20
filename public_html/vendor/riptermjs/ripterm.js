/**
 * RIPtermJS - Version 4
 * Copyright (c) 2011-2026 Carl Gorringe
 * https://carl.gorringe.org
 * https://github.com/cgorringe/RIPtermJS
 *
 * v3: 5/11/2021
 * v4: 1/22/2026 streaming file
 *
 * Renders RIPscrip v1.54 .RIP files in an HTML canvas or SVG.
 * (work in progress)
 **/

/**
 * This Source Code is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 *
 **/

// import BGI from './BGI.js'; // uncomment to use modules

////////////////////////////////////////////////////////////////////////////////
// TEST function

function testBGI (args) {

  if (!(args && ('canvasId' in args))) {
    console.error('missing canvasId!');
    return;
  }

  const canvas = document.getElementById(args.canvasId);
  const ctx = canvas.getContext('2d');
  const bgi = new BGI({ ctx, fontsPath:'fonts' });

  // drawing test
  bgi.setcolor(BGI.YELLOW);
  bgi.line(10, 10, 100, 100);

  bgi.setcolor(BGI.LIGHTRED);
  bgi.circle(100, 100, 50);

  bgi.setcolor(BGI.LIGHTBLUE);
  bgi.setfillstyle(BGI.SLASH_FILL, BGI.BLUE);
  bgi.bar3d(200, 200, 300, 300, 20, true);

  bgi.refresh();

  // TEST Fonts
/*
  const fontname = 'BOLD.CHR';
  bgi.fetchCHRFont(fontname);

  // add delay so font can load
  setTimeout(() => {
    console.log('Starting to draw font...');

    bgi.setcolor(BGI.LIGHTGREEN);
    bgi.moveto(150, 150);

    bgi.drawChar(32, fontname, 1, 0); // 32=space
    bgi.drawChar(33, fontname, 1, 0); // 33='!'
    bgi.drawChar(35, fontname, 1, 0); // 35='#' (bug)
    bgi.drawChar(65, fontname, 2, 0); // 65='A'
    bgi.drawChar(66, fontname, 3, 0); // 66='B'
    bgi.drawChar(67, fontname, 4, 0); // 67='C'
    bgi.drawChar(68, fontname, 5, 0); // 68='D'
    bgi.drawChar(69, fontname, 6, 0); // 69='E'

    bgi.refresh();

  }, 1000);
*/
/*
  // TEST Bitmap Fonts
  bgi.fetchPNGFont ('8x8.png', 0, 8, 8);
  bgi.fetchPNGFont ('8x14.png', 2, 8, 14);
  // add delay so font can load
  setTimeout(() => {
    console.log(bgi.bitfonts); // DEBUG
  }, 1000);
*/

}


////////////////////////////////////////////////////////////////////////////////
// Main Class

// html ids that may be set:
//   opts.canvasId  - main canvas (REQUIRED)
//   opts.svgId     - draw to an SVG tag (experimental)
//   opts.commandsId - show list of rip commands in div
//   opts.counterId - show command counter in div (e.g. '5 / 100')
//   opts.ssId      - screenshot canvas id
//   opts.diffId    - canvas id to display diff of main & screenshot canvases
//   opts.logId     - div id to display a debug log

class RIPterm {

  // class constants
  static get paletteEGA16 () { return [
    '#000', '#00a', '#0a0', '#0aa', '#a00', '#a0a', '#a50', '#aaa',
    '#555', '#55f', '#5f5', '#5ff', '#f55', '#f5f', '#ff5', '#fff'
  ]; }

  static get paletteEGA64 () { return [
    '#000', '#00a', '#0a0', '#0aa', '#a00', '#a0a', '#aa0', '#aaa',
    '#005', '#00f', '#0a5', '#0af', '#a05', '#a0f', '#aa5', '#aaf',
    '#050', '#05a', '#0f0', '#0fa', '#a50', '#a5a', '#af0', '#afa',
    '#055', '#05f', '#0f5', '#0ff', '#a55', '#a5f', '#af5', '#aff',
    '#500', '#50a', '#5a0', '#5aa', '#f00', '#f0a', '#fa0', '#faa',
    '#505', '#50f', '#5a5', '#5af', '#f05', '#f0f', '#fa5', '#faf',
    '#550', '#55a', '#5f0', '#5fa', '#f50', '#f5a', '#ff0', '#ffa',
    '#555', '#55f', '#5f5', '#5ff', '#f55', '#f5f', '#ff5', '#fff'
  ]; }

  // Character cell pixel dimensions for each size value (per RIP spec)
  static get FONT_DIMS () { return [
    { w: 8,  h: 8  },  // 0: 8×8
    { w: 7,  h: 8  },  // 1: 7×8
    { w: 8,  h: 14 },  // 2: 8×14
    { w: 7,  h: 14 },  // 3: 7×14
    { w: 16, h: 14 },  // 4: 16×14
  ]; }

  ////////////////////////////////////////////////////////////////////////////////

  constructor (opts) {

    if (opts && ('canvasId' in opts)) {

      // init default options
      this.opts = {
        'modemSpeed' : 0,        // simulate modem speed in bps (0 = no delay)
        'timeInterval' : 1,      // time between running commands (in miliseconds)
        'refreshInterval' : 100, // time between display refreshes (in miliseconds)
        'pauseOn' : [],          // debug: pauses on RIP command, e.g. ['F'] will pause on Flood Fill.
        'diffFGcolor' : '#C86',  // forground color for diff pixels that don't match.
        'diffBGcolor' : '#222',  // background color for diff pixels that match.
        'svgPrefix' : 'rip',     // used to prefix internal SVG ids
        'logQuiet' : false,      // set to true to stop logging to console
        'fontsPath' : 'fonts',
        'iconsPath' : 'icons',
        'origButtons' : true,    // set true to use original selected button style
        'svgShowIcons' : true,
        'svgEmbedIcons' : true,  // true: embed icons, false: use relative URL to .png
        'svgGetImage' : true,    // adds RIP_GET_IMAGE & RIP_PUT_IMAGE to SVG.

        // these options copied from prior version are not implemented yet.
        'floodFill' : true,
        'debugVerbose' : false,  // verbose flag
        'debugFillBuf' : false,  // display flood-fill buffer in canvas instead of normal drawings.
      };

      // assign or overwrite opts with passed-in options
      Object.entries(opts).forEach( ([k, v]) => { this.opts[k] = v } );

      // init other vars
      this.ripFile;         // v4 File type
      this.ripURL;          // v4 string
      this.ripStream;       // v4 stream (throttled)
      this.inStream;        // v4 stream
      this.ripStopped = true;
      this.outCommands = '';
      this.startTime = 0;
      this.cmdi = 0;        // command counter
      this.refTimer = null; // refresh interval timer
      this.clipboard = {};  // { x:int, y:int, width:int, height:int, data:Uint8ClampedArray }
      this.buttonStyle = {};
      this.buttons = [];  // array of active button objects
      this.buttonClicked = null; // last clicked button object
      this.withinButton = false;
      this.controlChars = this.initControlChars();
      this.textWindow = {};

      // debug options
      this.commandsDiv = ('commandsId' in opts) ? document.getElementById(opts.commandsId) : null;
      this.counterDiv = ('counterId' in opts) ? document.getElementById(opts.counterId) : null;
      this.coordsDiv = ('coordsId' in opts) ? document.getElementById(opts.coordsId) : null;
      this.hiliteCmdFlag = false;
      this.diffActive = false;
      if ('logId' in opts) {
        this.logId = opts.logId;
        this.logDiv = document.getElementById(opts.logId);
      }
      if ('ssId' in opts) {
        this.canvasSS = document.getElementById(opts.ssId);
        this.ctxSS = (this.canvasSS && this.canvasSS.getContext) ? this.canvasSS.getContext('2d') : null;
      }
      if ('diffId' in opts) {
        this.canvasDiff = document.getElementById(opts.diffId);
        this.ctxDiff = (this.canvasDiff && this.canvasDiff.getContext) ? this.canvasDiff.getContext('2d') : null;
        this.diffActive = true;
      }

      // init canvas
      this.canvas = document.getElementById(opts.canvasId);
      this.ctx = this.canvas.getContext('2d'); // , { alpha: false } ???

      this.isRunning = false;
      this.isFullscreen = false;
      this.backup = {};
      this.initFullScreen(this.canvas);

      // init BGI & SVG
      this.svg = ('svgId' in opts) ? document.getElementById(opts.svgId) : null;
      if (typeof BGI === 'undefined') {
        this.log('err', 'BGI() missing! Need to load BGI.js!');
      }
      else if (this.svg && (typeof BGIsvg === 'undefined')) {
        this.log('err', 'BGIsvg() missing! Need to load BGIsvg.js after BGI.js when using "svgId" option!');
      }
      else {
        this.bgi = (this.svg && (this.svg instanceof SVGElement))
          ? new BGIsvg({
            ctx: this.ctx,
            svg: this.svg,
            prefix: this.opts.svgPrefix,
            fontsPath: this.opts.fontsPath,
            iconsPath: this.opts.iconsPath,
            svgShowIcons: this.opts.svgShowIcons,
            svgEmbedIcons: this.opts.svgEmbedIcons,
            svgGetImage: this.opts.svgGetImage,
            log: (type, msg) => { this.log(type, msg) }
          })
          : new BGI({
            ctx: this.ctx,
            fontsPath: this.opts.fontsPath,
            iconsPath: this.opts.iconsPath,
            log: (type, msg) => { this.log(type, msg) }
          });

        // set that weird aspect ratio used in original EGA-mode RipTerm DOS version.
        //this.bgi.setaspectratio(371, 480); // = 0.7729
        this.bgi.setaspectratio(372, 480); // better
        this.initCommands();

        // must do once
        this.handleMouseEvents = this.handleMouseEvents.bind(this);
        this.setupCmdHover();
        this.setupCoordsMouseEvents();
        //this.setupMouseTestHandler(); // DEBUG testing mouse coords in bgi
      }
    }
    else {
      console.error('RIPterm() missing canvasId!');
    }

  }

  // call this after new RIPterm() to load all the fonts.
  async initFonts () {
    await this.bgi.initFonts();
  }

  // create a table of Unicode control char symbols.
  initControlChars () {
    // for 0x00(NUL) thru 0x1F
    let cchars = Object.fromEntries(
      Array.from({ length: 0x20 }, (_, i) => [
        String.fromCharCode(i), String.fromCodePoint(0x2400 + i)
      ])
    );
    cchars['\x7F'] = '\u2421'; // DEL
    return cchars;
  }

  initFullScreen (canvas) {
    if (canvas.requestFullscreen) {
      canvas.addEventListener("fullscreenchange", (event) => { this.fullscreenchange(event) }, false);
    }
    else if (canvas.webkitRequestFullscreen) {
      canvas.addEventListener("webkitfullscreenchange", (event) => { this.fullscreenchange(event) }, false);
    }
  }

  // send log messages to a div, if given logId option.
  log (type, msg) {
    if (this.logDiv) {
      // 'type' and 'out' are now the same
      //const typeStrings = { 'term':'trm', 'rip':'rip', 'bgi':'bgi', 'svg':'svg', 'err':'!!!', 'font':'fnt' }
      //const out = typeStrings[type] || type;
      const out = type;
      this.logDiv.innerHTML += `<span class="${this.logId}-${type}">${out}</span> ${msg}<br>`;
      this.logDiv.scrollTop = this.logDiv.scrollHeight; // autoscrolls
    }
    if (type === 'err') {
      console.error(msg);
    }
    else if (this.opts.logQuiet === false) {
      console.log(msg);
    }
  }

  ////////////////////////////////////////////////////////////////////////////////
  // Public methods

  async play () {
    this.log('trm', 'play()');
    if (this.ctx && this.ripStream) {
      // v4
      this.startTime = new Date();
      this.isRunning = true;
      if (this.refTimer) { window.clearTimeout(this.refTimer); this.refTimer = null; }
      this.refTimer = window.setTimeout(() => { this.refreshCanvas() }, this.opts.refreshInterval);
      if (this.ripStopped) {
        this.ripStopped = false;
        await this.reloadStream();
      }
      if (await this.playStream()) {
        await this.stop();
      }
    }
    else {
      this.log('trm', 'Must set "canvasId" and load a RIP first.');
    }
  }

  async pause () {
    this.log('trm', 'pause()');
    this.isRunning = false;
    if (this.commandsDiv) { this.commandsDiv.innerHTML = this.outCommands; }
  }

  async stop () {
    this.log('trm', 'stop()');
    this.isRunning = false;
    this.ripStopped = true;
    if (this.startTime > 0) {
      const timeDiff = (Date.now() - this.startTime) / 1000;
      this.startTime = 0;
      this.log('rip', `Rendered in ${timeDiff.toFixed(2)} seconds`);
    }
    if (this.refTimer) { window.clearTimeout(this.refTimer); this.refTimer = null; }
    this.refreshCanvas();
    if (this.commandsDiv) { this.commandsDiv.innerHTML = this.outCommands; }
  }

  reset () {
    this.log('trm', 'reset()');
    this.isRunning = false;
    this.ripStopped = true;
    this.bgi.graphdefaults();
    this.bgi.cleardevice();
    this.clearAllButtons();
    this.clearDiff();
    if (this.refTimer) { window.clearTimeout(this.refTimer); this.refTimer = null; }
    this.refreshCanvas();
    this.cmdi = 0;
    this.psVars = {};
    if (this.counterDiv) { this.counterDiv.innerHTML = ''; }
    this.outCommands = '';
    if (this.commandsDiv) { this.commandsDiv.innerHTML = ''; }
  }

  clear () {
    this.log('trm', 'clear()');
    this.bgi.cleardevice();
    this.clearDiff();
    this.refreshCanvas();
  }

  fullscreen () {
    this.log('trm', 'fullscreen()');
    if (!this.canvas) { return }
    if (this.canvas.requestFullscreen) {
      this.canvas.requestFullscreen();
    }
    else if (this.canvas.webkitRequestFullscreen) {
      this.canvas.webkitRequestFullscreen();
    }
  }

  // called when entering and exiting full screen
  async fullscreenchange (event) {
    if (!this.isFullscreen) {
      // entering fullscreen
      this.log('trm', 'Entering Fullscreen'); // DEBUG
      this.isFullscreen = true;
      this.backup.canvasWidth = event.target.style.width;
      this.backup.canvasHeight = event.target.style.height;
      const winW = window.innerWidth, winH = window.innerHeight;
      event.target.style.width = winW + 'px';
      event.target.style.height = winH + 'px';
      this.reset();
      await this.play();
    }
    else {
      // exiting fullscreen
      this.log('trm', 'Exiting Fullscreen'); // DEBUG
      this.isFullscreen = false;
      event.target.style.width = this.backup.canvasWidth;
      event.target.style.height = this.backup.canvasHeight;
    }
  }


  ////////////////////////////////////////////////////////////////////////////////

  refreshCanvas () {
    if (this.counterDiv) { this.counterDiv.innerHTML = this.cmdi.toLocaleString(); }
    this.bgi.refresh();
    if (this.diffActive) { this.refreshDiff(); }
    if (this.isRunning) {
      this.refTimer = window.setTimeout(() => { this.refreshCanvas() }, this.opts.refreshInterval);
    }
  }

  // output html to commandsDiv
  outputCmdsHtml(count, cmd0, args) {

    let outHtml = '';
    if (this.opts.pauseOn.includes(cmd0)) {
      // RIP command paused
      const o = this.cmd[cmd0](args);
      const oText = `${count}: ` + (o ? JSON.stringify(o).replaceAll('"', '').replaceAll(',', ', ') : 'unknown');
      outHtml = `<div class="rip-cmd" title="${oText}"><span class="cmd-paused">${cmd0}</span>${args}<br></div>`;
    }
    else if (this.cmd[cmd0]) {
      // RIP command supported
      const o = this.cmd[cmd0](args);
      const oText = `${count}: ` + (o ? JSON.stringify(o).replaceAll('"', '').replaceAll(',', ', ') : 'unknown');
      const oClass = (o && o.run) ? 'cmd-ok' : 'cmd-not';
      outHtml = `<div class="rip-cmd" title="${oText}"><span class="${oClass}">${cmd0}</span>${args}<br></div>`;
    }
    else {
      // RIP command NOT supported
      outHtml = `<div title="${count}"><span class="cmd-not">${cmd0}</span>${args}<br></div>`;
    }
    return outHtml;
  }

  clearFile () {
    this.cmdi = 0;
    this.outCommands = '';
    this.refreshCanvas();
    if (this.counterDiv) { this.counterDiv.innerHTML = ''; }
    if (this.commandsDiv) { this.commandsDiv.innerHTML = ''; }
  }

  clearScreenshot () {
    if (this.canvasSS && this.ctxSS) {
      this.ctxSS.clearRect(0, 0, this.canvasSS.width, this.canvasSS.height);
    }
  }

  // Load and draw a screenshot image file inside a canvas
  // if this.ctxSS ('ssId' option) is set.
  readScreenshot (url) {
    this.log('trm', 'readScreenshot(): ' + url);

    if (this.canvasSS && this.ctxSS) {
      this.ctxSS.clearRect(0, 0, this.canvasSS.width, this.canvasSS.height);
      let img = new Image();
      img.onload = () => {
        this.ctxSS.drawImage(img, 0, 0);
        if (this.diffActive) {
          this.refreshDiff();
        } else {
          this.clearDiff();
        }
      }
      img.src = url;
    }
  }

  clearDiff () {
    if (this.ctxDiff) {
      this.ctxDiff.save();
      this.ctxDiff.fillStyle = this.opts.diffBGcolor;
      this.ctxDiff.fillRect(0, 0, this.ctxDiff.canvas.width, this.ctxDiff.canvas.height);
      this.ctxDiff.restore();
    }
  }

  // Draws diff between Screenshot & Main canvas,
  // where black pixels = match, white pixels = don't match.
  // Both 'ssId' and 'diffId' options must be set.
  // All 3 canvas width & heights must match!
  // (only works in browser, not using node's 'A8' pixel format.)
  refreshDiff () {

    if ( this.canvas && this.ctx && this.canvasSS && this.ctxSS && this.canvasDiff && this.ctxDiff &&
      (this.canvas.width === this.canvasSS.width) && (this.canvas.width === this.canvasDiff.width) &&
      (this.canvas.height === this.canvasSS.height) && (this.canvas.height === this.canvasDiff.height) )
    {
      // prepare image data
      const w = this.canvas.width, h = this.canvas.height;
      let imgMain = this.ctx.getImageData(0, 0, w, h);
      let imgSS = this.ctxSS.getImageData(0, 0, w, h);
      let imgDiff = this.ctxDiff.getImageData(0, 0, w, h);

      // grab color options
      const [fgR, fgG, fgB] = this.hex2rgb(this.opts.diffFGcolor);
      const [bgR, bgG, bgB] = this.hex2rgb(this.opts.diffBGcolor);

      // compare image diffs
      const delta = 40; // accounts for changes in color gamma in source images
      const dlen = imgMain.data.length; // 4 ints in array per pixel
      for (let i=0; i < dlen; i+=4) {
        if ( (Math.abs(imgMain.data[i+0] - imgSS.data[i+0]) < delta)   // R
          && (Math.abs(imgMain.data[i+1] - imgSS.data[i+1]) < delta)   // G
          && (Math.abs(imgMain.data[i+2] - imgSS.data[i+2]) < delta) ) // B
        {
          // pixels match: draw dark
          imgDiff.data[i+0] = bgR;
          imgDiff.data[i+1] = bgG;
          imgDiff.data[i+2] = bgB;
          imgDiff.data[i+3] = 255;
        }
        else {
          // pixels don't match: draw bright
          imgDiff.data[i+0] = fgR;
          imgDiff.data[i+1] = fgG;
          imgDiff.data[i+2] = fgB;
          imgDiff.data[i+3] = 255;
        }
      }

      // draw imgDiff to diff canvas
      this.ctxDiff.putImageData(imgDiff, 0, 0);
    }
  }

  // convert to uppercase and add .ICN extension
  fixIconFilename (filename) {
    let fname = null;
    if ((typeof filename === 'string') && (filename.length > 0)) {
      fname = filename.toUpperCase();
      if (!fname.includes('.')) { fname += '.ICN'; }
    }
    return fname;
  }

  // convert to hot icon filename by replacing extension with .HIC, else returns null.
  hotIconFilename (filename) {
    let fname = this.fixIconFilename(filename);
    fname = (fname && fname.includes('.ICN')) ? fname.replace('.ICN', '.HIC') : null;
    return fname;
  }

  // fetches the given array of icon files and caches them.
  async loadIcons (filenames) {
    return (await Promise.all(filenames.map(async fname => {
      const url = this.bgi.iconsPath + '/' + fname;
      this.bgi.icons[fname] = await this.bgi.fetchIcon(url);
    })));
  }


  ////////////////////////////////////////////////////////////////////////////////
  // v4 using file streaming

  async openFile (file) {

    file = file || this.ripFile;
    // TODO: how to check for type File?
    if (typeof file !== "object") { return; }
    this.ripFile = file;
    this.ripURL = undefined;
    this.log('trm', `openFile: ${file.name}`);

    const stream = file.stream();
    await this.setupStream(stream);
  }

  async openURL (url) {

    url = url || this.ripURL;
    if (typeof url !== "string") { return; }
    this.ripURL = url;
    this.ripFile = undefined;
    this.log('trm', `openURL: ${url}`);

    const response = await fetch(url);
    const stream = response.body;
    await this.setupStream(stream);
  }

  // 'speed' can be an int or a string
  async setModemSpeed (speed) {

    const speedNum = parseInt(speed);
    this.log('trm', `Speed set to ${speedNum.toLocaleString()} bps`);
    this.opts.modemSpeed = speedNum;
  }

  // Calculate buffer size based on modemSpeed & refreshInterval.
  // Size returned is number of bytes between refreshes.
  // If modemSpeed == 0 then returns 0.
  //
  calculateStreamBufferSize (speed = this.opts.modemSpeed, interval = this.opts.refreshInterval) {

    // Modem speed is in bits per second assuming 8N1 or 10 bits/byte.
    // speed / 10 => bytes per sec
    // interval is in 1/1000 secs (milliseconds)
    // return (interval / 1000) * (speed / 10)
    //
    // Example #1: 20 ms refresh = 50 Hz
    // (20 ms / 1000) * (1200 bps / 10) => 2.4 bytes (a tiny buffer!)
    // (20 ms / 1000) * (9600 bps / 10) => 19.2 bytes
    // (20 ms / 1000) * (14400 bps / 10) => 28.8 bytes
    // (20 ms / 1000) * (33600 bps / 10) => 67.2 bytes
    // (20 ms / 1000) * (56000 bps / 10) => 112 bytes
    //
    // Example #2: 50 ms refresh = 20 Hz
    // (50 ms / 1000) * (1200 bps / 10) => 6 bytes
    // (50 ms / 1000) * (9600 bps / 10) => 48 bytes
    // (50 ms / 1000) * (14400 bps / 10) => 72 bytes
    // (50 ms / 1000) * (33600 bps / 10) => 168 bytes
    // (50 ms / 1000) * (56000 bps / 10) => 280 bytes

    return Math.ceil((interval / 1000) * (speed / 10));
  }

  // copied from AI
  // chunkSize: bytes per chunk
  // delay: ms between chunks
  //
  createThrottleTransform({ chunkSize = 1024, delay = 50 } = {}) {

    this.log('trm', `createThrottleTransform() chunkSize:${chunkSize}, delay:${delay}`); // DEBUG

    let leftover = null;
    return new TransformStream({
      async transform(chunk, controller) {
        // Ensure Uint8Array
        if (!(chunk instanceof Uint8Array)) {
          chunk = new Uint8Array(chunk);
        }

        // Prepend leftover bytes from previous transform
        if (leftover) {
          const combined = new Uint8Array(leftover.length + chunk.length);
          combined.set(leftover);
          combined.set(chunk, leftover.length);
          chunk = combined;
          leftover = null;
        }

        let offset = 0;

        while (offset + chunkSize <= chunk.length) {
          controller.enqueue(chunk.slice(offset, offset + chunkSize));
          offset += chunkSize;

          if (delay > 0) {
            await new Promise(r => setTimeout(r, delay));
          }
        }

        // Save remainder for next chunk
        if (offset < chunk.length) {
          leftover = chunk.slice(offset);
        }
      },

      flush(controller) {
        if (leftover) {
          controller.enqueue(leftover);
          leftover = null;
        }
      }
    });
  }

  // call this after playing a stream to replay it
  async reloadStream (defaults = {}) {

    this.log('trm', 'reloadStream()'); // DEBUG
    const {
      file = this.ripFile,
      url = this.ripURL,
    } = defaults;

    this.cmdi = 0;
    this.outCommands = '';
    await this.releaseStream(this.ripStream);
    if (file) {
      await this.openFile(file);
    }
    else if (url) {
      await this.openURL(url);
    }
  }

  async releaseStream (stream) {
    if (stream) {
      this.psVars = {}; // clear playStream buffers
      //if (stream.locked && stream.releaseLock) {
      if (stream.releaseLock) {
        await stream.releaseLock();
      }
      await stream.cancel();
    }
  }

  // if stream is undefined, continue using this.inStream
  async setupStream (stream) {

    stream = stream || this.inStream;
    if (typeof stream === "undefined") { return; }
    const chunkSize = this.calculateStreamBufferSize();

    if (stream) {
      this.inStream = stream;
      if (chunkSize > 0) {
        const throttle = this.createThrottleTransform({
          chunkSize: chunkSize, delay: this.opts.refreshInterval
        });
        await this.releaseStream(this.ripStream);
        this.ripStream = stream.pipeThrough(throttle);
      }
      else {
        // don't throttle stream
        this.ripStream = stream;
      }
    }
  }

  // Read & parse stream implemented as a state machine.
  // Plays RIP commands and sends text to printANSI().
  //
  async playStream (defaults = {}) {
    let {
      stream = this.ripStream,
    } = defaults;

    this.log('trm', `playStream()`); // DEBUG
    if (!stream) { return false; }
    let reader = stream.getReader();

    // states
    const ST_START=1, ST_ANSI=2, ST_RIPCMD=3, ST_RIPARG=4;
    const ST_BANG=5, ST_BSLASH=6, ST_CR=7, ST_RIPBANG=8;

    // global vars
    const outerThis = this;
    this.psVars = this.psVars || {};
    this.psVars.state = this.psVars.state || ST_START;
    this.psVars.ansiBuf = this.psVars.ansiBuf || [];
    this.psVars.ripCmdBuf = this.psVars.ripCmdBuf || [];
    this.psVars.ripArgsBuf = this.psVars.ripArgsBuf || [];
    let state = this.psVars.state,  // state is a copy (by val)
      ansiBuf = this.psVars.ansiBuf,
      ripCmdBuf = this.psVars.ripCmdBuf,
      ripArgsBuf = this.psVars.ripArgsBuf;

    // functions
    async function sendToRIP (cmdBuf, argsBuf) {
      if (cmdBuf && argsBuf && cmdBuf.length > 0) {

        // Text Encoding:
        // Bytes are encoded "cp437" (MS-DOS) or "cp850", but neither are available.
        // "cp866" has box characters, but replaces latin/greek with cyrillic.
        // "windows-1252" changes too much.
        // "x-user-defined" converts 0x80-0xFF (128-255) to Unicode U+F780-U+F7FF (blanks)
        // which works for our use, as we can mask the upper byte to get the original value.
        // https://developer.mozilla.org/en-US/docs/Web/API/Encoding_API/Encodings

        const decoder = new TextDecoder("x-user-defined");
        const cmd0 = outerThis.replaceControlChars(decoder.decode(new Uint8Array(cmdBuf))) || '';
        const args = decoder.decode(new Uint8Array(argsBuf)) || '';
        cmdBuf.length = 0;
        argsBuf.length = 0;
        await outerThis.runRIPcmd(cmd0, args);
        outerThis.outCommands += outerThis.outputCmdsHtml(outerThis.cmdi, cmd0, args);
        outerThis.cmdi++;
      }
    }
    async function sendToANSI (buf) {
      if (buf && (buf.length > 0)) {
        await outerThis.printANSI(new Uint8Array(buf));
        buf.length = 0;
      }
    }

    // ASCII values:
    // 1=^A, 2=^B, 10=LF, 13=CR, 27=ESC, 33=!, 42=*, 92=\, 124=|

    // state machine
    async function nextByte (byte) {
      switch (state) {
      case ST_START:
        ripCmdBuf.length = 0;
        ripArgsBuf.length = 0;
        ansiBuf.push(byte);
        if ((byte === 33) || (byte === 1) || (byte === 2)) { state = ST_BANG; } // '!', ^A, ^B
        else if ((byte === 13) || (byte === 10)) { } // CR or LF
        else { state = ST_ANSI; }
        break;

      case ST_CR:
        if (byte === 10) { } // LF
        else if ((byte === 33) || (byte === 1) || (byte === 2)) { // '!', ^A, ^B
          ansiBuf.push(byte);
          state = ST_BANG;
        }
        else {
          ansiBuf.push(byte);
          state = ST_ANSI;
        }
        break;

      case ST_BANG:
        if (byte === 124) { // '|'
          ansiBuf.pop(); // pops '!' (or rare CTRL-A/B)
          await sendToANSI(ansiBuf);
          state = ST_RIPCMD;
        }
        else {
          ansiBuf.push(byte);
          state = ST_ANSI;
        }
        break;

      case ST_RIPBANG:
        // special case to handle '!' inside ST_RIPARG which are not properly escaped.
        if (byte === 124) { // '|' - new RIP command
          ripArgsBuf.pop(); // pops '!'
          await sendToRIP(ripCmdBuf, ripArgsBuf);
          state = ST_RIPCMD;
        }
        else { // continue ST_RIPARG
          ripArgsBuf.push(byte);
          state = ST_RIPARG;
        }
        break;

      case ST_ANSI:
        ansiBuf.push(byte);
        if ((byte === 13) || (byte === 10)) { state = ST_START; } // CR or LF
        else if ((byte === 1) || (byte === 2)) { state = ST_BANG; } // ^A or ^B
        else { }
        break;

      case ST_RIPCMD:
        ripCmdBuf.push(byte);
        if ((byte >= 48) && (byte <= 57)) { } // '0' to '9'
        else { state = ST_RIPARG; } // a letter, '*', '#', '!' or ESC
        break;

      case ST_RIPARG:
        if (byte === 124) { // '|'
          await sendToRIP(ripCmdBuf, ripArgsBuf);
          state = ST_RIPCMD;
        }
        else if ((byte === 13) || (byte === 10)) { // CR or LF
          await sendToRIP(ripCmdBuf, ripArgsBuf);
          state = ST_CR;
        }
        else if (byte === 33) { // '!' - start of next RIP command
          ripArgsBuf.push(byte);
          state = ST_RIPBANG;
        }
        else if (byte === 92) { // '\'
          state = ST_BSLASH;
        }
        else {
          ripArgsBuf.push(byte);
        }
        break;

      case ST_BSLASH:
        if ((byte === 13) || (byte === 10)) { } // CR or LF
        else {
          ripArgsBuf.push(byte);
          state = ST_RIPARG;
        }
        break;
      }
      outerThis.psVars.state = state;
    }

    // runloop
    try {
      while (this.isRunning) {
        // read next chunk of bytes
        // value is a Uint8Array or undefined
        const { value, done } = await reader.read();
        if (done) {
          this.log('trm', 'Stream complete');
          return true;
        }
        else if (value) {
          // parse all the new bytes read in
          const buffer = Array.from(value);
          for (let i=0; i < buffer.length; i++) {
            await nextByte(buffer[i]);
          }
        }
      }
    }
    finally {
      if (reader) { reader.releaseLock(); }
    }
    return false;
  }

  // run RIP instruction given command code and args
  // TODO: update to use Uint8Arrays to support ESC and extended ASCII codes.
  //
  async runRIPcmd (cmd0, args) {
    if (this.cmd[cmd0]) {
      const o = this.cmd[cmd0](args);
      if (o && o.run) { await o.run({}); }
      //this.log('rip', `!|${cmd0}${args} ${JSON.stringify(o)}`); // DEBUG
    }
    if (this.opts.pauseOn.includes(cmd0)) {
      if (!this.opts.floodFill && (cmd0 == 'F')) { }
      else { await this.stop(); }
    }
  }

  // Replace control chars in text with Unicode symbols.
  // must set this.controlChars = this.initControlChars() first.
  // returns modified text for display.
  //
  replaceControlChars (text) {
    if (typeof text !== "string") { return ''; }
    return (this.controlChars) ? text.split('').map(c => this.controlChars[c] ?? c).join('') : text;
  }

  // Text to output to the Text Window.
  // bytes is an Uint8Array
  async printANSI (bytes) {
    // TODO: this is a stub for now
    //this.log('ans', `ANSI: ${bytes}`); // DEBUG

    const text = new TextDecoder("x-user-defined").decode(bytes);
    const otext = this.replaceControlChars(text);
    this.log('ans', `${otext}`); // DEBUG
  }


  ////////////////////////////////////////////////////////////////////////////////
  // Private methods

  // hex is '#rgb' or '#rrggbb', returns [R, G, B] where values are 0-255
  hex2rgb (hex) {
    return (hex.length == 4) ?
      ['0x' + hex[1] + hex[1] | 0, '0x' + hex[2] + hex[2] | 0, '0x' + hex[3] + hex[3] | 0]:
      ['0x' + hex[1] + hex[2] | 0, '0x' + hex[3] + hex[4] | 0, '0x' + hex[5] + hex[6] | 0];
  }

  // Given an object or array
  // returns true if none of the values are NaN.
  noNaNs (obj) {
    if (typeof obj === 'object') {
      return Object.values(obj).every(value => !Number.isNaN(value));
    }
    else if (typeof obj === 'array') {
      return obj.every(value => !Number.isNaN(value));
    }
    return false;
  }

  // convert these "\!" "\|" "\\" to normal text.
  // NOT USED
  unescapeRIPtext (text) {
    text = text.replace(/\\\!/g, '!');
    text = text.replace(/\\\|/g, '|');
    text = text.replace(/\\\\/g, '\\');
    return text;
  }

  // Extracts command code + args from RIP instruction.
  // TODO: not coded to work with ESC character commands.
  // TODO: this is the OLD v3 parsing, need to update!
  //
  parseRIPcmd (inst) {
    if (!inst) { return ['', '']; }
    let args = inst;
    const cmd = /^[0-9]*./.exec(inst)[0];
    if (cmd) {
      // grab everything after cmd string
      args = inst.substring(cmd.length);
      // remove all newlines (NOT TESTED)
      args = args.replace(/\r?\n|\r/gm, '');
    }
    return [cmd, args];
  }

  // args = encoded string following the command.
  // fmt = string format.
  // e.g. args = '003PHR9P' where 'B' is command not passed in, '003PHR9P' are 4 2-digit args.
  // e.g. fmt = '2222' means extract 4 2-digit ints, converting from base36.
  // returns an array of numbers.
  // still need to figure out how to parse strings out.
  // Supports variable-width args.
  // For any args missing or invalid, parseInt() will return NaN values.
  //
  parseRIPargs (args, fmt) {
    let pos = 0, ret = [];
    Array.from(fmt).forEach(f => {
      switch (f) {
        case '1': ret.push( parseInt(args.substring(pos, pos+1), 36) ); pos += 1; break;
        case '2': ret.push( parseInt(args.substring(pos, pos+2), 36) ); pos += 2; break;
        case '3': ret.push( parseInt(args.substring(pos, pos+3), 36) ); pos += 3; break;
        case '4': ret.push( parseInt(args.substring(pos, pos+4), 36) ); pos += 4; break;
        case '5': ret.push( parseInt(args.substring(pos, pos+5), 36) ); pos += 5; break;
        case '6': ret.push( parseInt(args.substring(pos, pos+6), 36) ); pos += 6; break;
        case '7': ret.push( parseInt(args.substring(pos, pos+7), 36) ); pos += 7; break;
        case '8': ret.push( parseInt(args.substring(pos, pos+8), 36) ); pos += 8; break;
        case '9': ret.push( parseInt(args.substring(pos, pos+9), 36) ); pos += 9; break;
        case '*': ret.push( args.substring(pos) ); break;
        default:
      }
    });
    return ret;
  }

  // takes in an array of keys (strings)
  // returns an object using keys (strings) and values (ints)
  // Supports variable-width args.
  // For any args missing or invalid, parseInt() will return NaN values.
  //
  parseRIPargs2 (args, fmt, keys) {
    let pos=0, i=0, ret = {};
    Array.from(fmt).forEach(f => {
      switch (f) {
        case '1': ret[keys[i]] = parseInt(args.substring(pos, pos+1), 36); pos += 1; break;
        case '2': ret[keys[i]] = parseInt(args.substring(pos, pos+2), 36); pos += 2; break;
        case '3': ret[keys[i]] = parseInt(args.substring(pos, pos+3), 36); pos += 3; break;
        case '4': ret[keys[i]] = parseInt(args.substring(pos, pos+4), 36); pos += 4; break;
        case '5': ret[keys[i]] = parseInt(args.substring(pos, pos+5), 36); pos += 5; break;
        case '6': ret[keys[i]] = parseInt(args.substring(pos, pos+6), 36); pos += 6; break;
        case '7': ret[keys[i]] = parseInt(args.substring(pos, pos+7), 36); pos += 7; break;
        case '8': ret[keys[i]] = parseInt(args.substring(pos, pos+8), 36); pos += 8; break;
        case '9': ret[keys[i]] = parseInt(args.substring(pos, pos+9), 36); pos += 9; break;
        case '*': ret[keys[i]] = args.substring(pos); break;
        default:
      }
      i += 1;
    });
    return ret;
  }

  // Parses RIP_POLYGON, RIP_FILL_POLYGON, and RIP_POLYLINE
  // which have a variable number of arguments in the form:
  //  npoints:2, x1:2, y1:2, ... xn:2, yn:2
  // For any args missing or invalid, parseInt() will return NaN values.
  //
  parseRIPpoly (args) {
    let xy = 0, ret = [];
    const npoints = parseInt(args.substring(0, 2), 36);
    ret.push(npoints);
    if (npoints <= 512) { // false if npoints == NaN
      for (let n=0; n < npoints * 2; n++) {
        xy = parseInt(args.substring(n*2 + 2, n*2 + 4), 36);
        ret.push(xy);
      }
    }
    return ret;
  }

  // extracts icon filename(s) from RIP_LOAD_ICON (1I) or RIP_BUTTON (1U).
  // returns an array of strings, else empty array.
  // most often 1 filename, but returns 2 if there's a "hot icon" button.
  // modifies: this.buttonStyle
  // NOT USED
  //
  async parseIconNames (cmd0, args) {
    let fnames = [];
    if (cmd0 === '1I') {
      // RIP_LOAD_ICON
      if (args.length > 9) {
        const [x, y, mode, clipflag, res, filename] = this.parseRIPargs(args, '22212*');
        fnames.push(this.fixIconFilename(filename));
      }
    }
    else if (cmd0 === '1U') {
      // RIP_BUTTON
      if (args.length > 12) {
        const [x0, y0, x1, y1, hotkey, flags, res, text] = this.parseRIPargs(args, '2222211*');
        const [icon_name, label_text, host_cmd] = text.split("<>");
        const fname1 = this.fixIconFilename(icon_name);
        if (fname1) { fnames.push(fname1); }

        // check current button style if button displays a Hot Icon
        if (this.buttonStyle && ('flags' in this.buttonStyle) && (this.buttonStyle.flags & 4096)) {
          const fname2 = this.hotIconFilename(icon_name);
          if (fname2) { fnames.push(fname2); }
        }
      }
    }
    else if (cmd0 === '1B') {
      // RIP_BUTTON_STYLE (1B)
      // running this updates this.buttonStyle
      if (this.cmd[cmd0]) {
        const o = this.cmd[cmd0](args);
        if (o && o.run) { await o.run({}); }
      }
    }
    return fnames;
  }

  // Setup events for click, mouseover, and mouseout of individual RIP commands.
  // only call this once
  setupCmdHover () {

    this.hoverTimer = null;
    this.HOVER_DELAY = 300;

    document.addEventListener("click", (event) => {
      if (event.target.classList.contains("rip-cmd")) {
        const inst = event.target.textContent;
        this.log('rip', event.target.title);
      }
    });

    document.addEventListener("mouseover", (event) => {
      const item = event.target.closest(".rip-cmd");
      if (!item) return;
      if (!item.contains(event.relatedTarget)) {
        this.hoverTimer = setTimeout(async () => {
          const inst = event.target.textContent;
          //console.log("mouseover: " + inst); // DEBUG
          await this.onHoverCmd(inst);
          this.hoverTimer = null;
        }, this.HOVER_DELAY);
      }
      // if (event.target.classList.contains("rip-cmd")) { }
    });

    document.addEventListener("mouseout", async (event) => {
      const item = event.target.closest(".rip-cmd");
      if (!item) return;
      if (!item.contains(event.relatedTarget)) {
        if (this.hoverTimer) {
          clearTimeout(this.hoverTimer);
          this.hoverTimer = null;
        }
        else {
          const inst = event.target.textContent;
          //console.log("mouse out: " + inst); // DEBUG
          await this.onHoverCmd(inst);
        }
      }
    });
  }

  // onClick handler for commands in commandsDiv. (no longer used)
  async clickCmd (cmd0, args) {
    await this.highlightCmd(cmd0, args, true);
  }

  // Click event handler that outputs RIP command details to log. (no longer used)
  onClickCmd (inst) {
    const [c, args] = this.parseRIPcmd(inst);
    if (this.cmd[c]) {
      const o = this.cmd[c](args);
      if (o) {
        this.log('rip', `!|${inst} ${JSON.stringify(o)}`);
      }
    }
  }

  // Event handler called on mouseover & mouseout of RIP commands
  // to highlight RIP commands in the canvas. hiliteCmdFlag must be set true.
  // inst is a full RIP command string (cmd + args).
  //
  async onHoverCmd (inst) {
    if (this.hiliteCmdFlag) {
      const [cmd0, args] = this.parseRIPcmd(inst);
      await this.highlightCmd(cmd0, args, false);
    }
  }

  // Highlight command by setting writemode to XOR then redraw it.
  async highlightCmd (cmd0, args, logFlag = false) {

    // FIXME: pie slices will flood canvas ['I','i']
    // need to fix bgi.sector() so that it doesn't call bgi.floodfill()
    // instead draw a box around it.
    // can't highlight '@' text, as size depends on previously stored font info that changes.

    if (this.cmd[cmd0]) {
      const o = this.cmd[cmd0](args);
      if (o && o.run) {
        if (logFlag) { this.log('rip', `${cmd0}${args} ${JSON.stringify(o)}`); }

        // prepare to draw using XOR
        let activeFlag = this.bgi.svgActive;
        this.bgi.svgActive = false;
        this.bgi.pushState();
        this.bgi.setwritemode(BGI.XOR_PUT);
        this.bgi.setcolor(BGI.WHITE); // BGI.LIGHTRED
        this.bgi.setbkcolor(BGI.YELLOW);
        this.bgi.setlinestyle(BGI.SOLID_LINE, 0xFFFF, BGI.NORM_WIDTH);
        //this.bgi.setlinestyle(BGI.SOLID_LINE, 0xFFFF, BGI.THICK_WIDTH); // buggy
        // thick ovals don't draw correctly using XOR
        // line options: BGI.SOLID_LINE or BGI.DASHED_LINE
        this.bgi.setfillstyle(BGI.SOLID_FILL, BGI.YELLOW);

        // draw and restore state
        await o.run({ hilite: true });
        this.refreshCanvas();
        this.bgi.popState();
        this.bgi.svgActive = activeFlag;
      }
    }
  }

  ////////////////////////////////////////////////////////////////////////////////
  // Mouse event handlers

  // testing BGI mouse handler when canvas is clicked.
  setupMouseTestHandler () {
    this.bgi.registermousehandler(BGI.WM_LBUTTONDOWN, (function(x, y) {
      this.log('bgi', `mouse x,y (${x}, ${y})`);
    }).bind(this));
  }

  // setup mouse events for debug coords display
  setupCoordsMouseEvents () {

    // binding 'this' in event handler to class instance
    this.handleCoordsMouseEvents = this.handleCoordsMouseEvents.bind(this);
    this.canvas.addEventListener('mousemove', this.handleCoordsMouseEvents);
    this.canvas.addEventListener('mouseout', this.handleCoordsMouseEvents);
    if (this.canvasSS) {
      this.canvasSS.addEventListener('mousemove', this.handleCoordsMouseEvents);
      this.canvasSS.addEventListener('mouseout', this.handleCoordsMouseEvents);
    }
    if (this.canvasDiff) {
      this.canvasDiff.addEventListener('mousemove', this.handleCoordsMouseEvents);
      this.canvasDiff.addEventListener('mouseout', this.handleCoordsMouseEvents);
    }
  }

  // event handler for displaying mouse coords
  handleCoordsMouseEvents (e) {

    // debug coords display
    if (this.coordsDiv) {
      const [x, y] = this.bgi._mouseCoords(e);
      if ((e.type === "mouseout") || (x < 0) || (y < 0)) {
        this.coordsDiv.innerHTML = '';
      }
      else if (e.type === "mousemove") {
        this.coordsDiv.innerHTML = `${x}, ${y}`;
      }
    }
  }

  // Draws a Button or Mouse Region, then refreshes canvas.
  // b: object. See createButton() & createMouseRegion() for properties.
  // isDown: true = selected
  updateButton (b, isDown) {

    // FIXME: viewports not implemented yet! (assumes viewport is entire canvas)
    if (typeof b === 'undefined') { return; }
    if (b.isButton) {
      // button
      const bstyle = b.style;
      if ((bstyle.flags & 2) || (bstyle.flags & 4096)) {
        // button is invertable or a Hot Icon
        const bevsize = (bstyle.flags & 512) ? ((bstyle.bevsize > 0) ? bstyle.bevsize : 1) : 0;
        this.drawButton(b.ax1 + bevsize, b.ay1 + bevsize, b.ax2 - bevsize, b.ay2 - bevsize, b, isDown);
      }
    }
    else if (b.invertFlag) {
      // mouse region
      this.drawInvertedBar(b.ax1, b.ay1, b.ax2, b.ay2);
    }
    this.refreshCanvas();
  }

  // selects given button if not already, and unselects all other buttons in same group.
  // uses this.buttons[], modifies button.flags and flags in all buttons in the same group.
  selectRadioButton (button) {

    // if button already selected, ignore it
    if (button && (button.flags & 1)) { return; }

    // only work with radio buttons
    if (button.style.flags & 16384) {

      // cycle thru all radio buttons in the same group
      for (let b of this.buttons) {
        // unselect button only if it's already selected
        if (b.isButton && (b.flags & 1) && (b.style.flags & 16384) && (b.style.grp_no === button.style.grp_no)) {
          b.flags ^= 1; // clear selected flag using XOR
          this.updateButton(b, false);
        }
      }
      // select the button
      this.updateButton(button, true);
      button.flags |= 1; // set selected flag
    }
  }

  // toggles the selected state of a button, and updates selected flag.
  // useful for toggling check buttons, but works with any selected buttons.
  toggleButton (button) {
    const toggled = (button.flags & 1) ? false : true;
    button.flags ^= 1; // toggle selected flag using XOR
    this.updateButton(button, toggled);
  }

  // Activates Button & Mouse Region events,
  // such as normally done after the '#' RIP command.
  // activate: true = turn on, false = turn off mouse events on canvas.
  activateMouseEvents (activate) {
    if (activate) {
      // activate mouse events
      this.canvas.addEventListener('pointerup', this.handleMouseEvents );
      this.canvas.addEventListener('pointerdown', this.handleMouseEvents );
      this.canvas.addEventListener('pointermove', this.handleMouseEvents );
      this.canvas.addEventListener('pointerleave', this.handleMouseEvents );
    }
    else {
      // deactivate mouse events
      this.canvas.removeEventListener('pointerup', this.handleMouseEvents );
      this.canvas.removeEventListener('pointerdown', this.handleMouseEvents );
      this.canvas.removeEventListener('pointermove', this.handleMouseEvents );
      this.canvas.removeEventListener('pointerleave', this.handleMouseEvents );
    }
  }

  // Event listener handler for mouseup, mousedown, mousemove, and mouseleave events.
  // uses: this.buttons[], this.buttonClicked, this.withinButton
  // 'this' is binded to class instance using this.handleMouseEvents.bind(this)
  //
  handleMouseEvents (e) {

    let [x, y] = this.bgi._mouseCoords(e);
    let isWithin = false;

    for (const b of this.buttons) {
      if ((x > b.ax1) && (x < b.ax2) && (y > b.ay1) && (y < b.ay2)) {
        // within area of mouse region
        isWithin = true;
        if ((e.type === 'pointerdown') && (e.button == 0)) {
          // only if main (left) mouse button pressed
          this.updateButton(b, true);
          this.buttonClicked = b;
        }
        else if ((this.buttonClicked !== null) && (e.type === 'pointerup')) {
          this.updateButton(this.buttonClicked, false);

          // only if within previously clicked button or mouse region
          if (b === this.buttonClicked) {
            this.sendHostCommand(b.cmdText);

            // handle radio & check buttons
            if (b.isButton) {
              if (b.style.flags & 16384) {
                // radio button
                this.selectRadioButton(b);
              }
              else if (b.style.flags2 & 1) {
                // toggle check button
                this.toggleButton(b);
              }
            }
          }

          this.buttonClicked = null;
        }
        break;
      }
    }

    // also unselect clicked button if mouseup outside any region
    if ( (isWithin == false) && (this.buttonClicked !== null) && ((e.type === 'pointerup') || (e.type === 'pointerleave')) ) {
      this.updateButton(this.buttonClicked, false);
      this.buttonClicked = null;
    }

    // change cursor if isWithin changed
    if (this.withinButton !== isWithin) {
      this.withinButton = isWithin;
      this.canvas.style.cursor = (isWithin) ? 'pointer' : 'auto';
    }
  }

  // handles sending text to a host from clicking buttons and mouse regions.
  sendHostCommand (text) {

    // TODO: $ variables & host command templates

    this.log('trm', 'send to host: ' + text);
    // Emit event for external listeners (e.g. BBS game clients)
    if (this.onHostCommand) {
      this.onHostCommand(text);
    }
  }


  ////////////////////////////////////////////////////////////////////////////////
  // Drawing methods

  drawBeveledBox (x1, y1, x2, y2, topl_col, botr_col, corner_col, corner_size) {

    if (corner_col && corner_size) {
      const clen = corner_size - 1;

      this.bgi.pushState();
      this.bgi.setlinestyle(BGI.SOLID_LINE);

      // draw bevel polygons
      this.bgi.setcolor(botr_col); // botr_col
      this.bgi.setfillstyle (BGI.SOLID_FILL, botr_col);
      this.bgi.fillpoly(7, [x2-1,y2-1, x1,y2-1, x1+clen+1,y2-clen-2, x2-clen-1,y2-clen-2, x2-clen-1,y1+clen, x2-1,y1-1, x2-1,y2-1] );

      this.bgi.setcolor(topl_col); // topl_col
      this.bgi.setfillstyle (BGI.SOLID_FILL, topl_col);
      this.bgi.fillpoly(7, [x1,y1-1, x2-1,y1-1, x2-clen-2,y1+clen, x1+clen,y1+clen, x1+clen,y2-clen-2, x1,y2-2, x1,y1-1] );

      // draw corners
      this.bgi.line(x1, y1-1, x1+clen, y1+clen-1, corner_col, BGI.COPY_PUT, BGI.SOLID_LINE, 1);
      this.bgi.line(x2-1, y1-1, x2 - clen-1, y1+clen-1, corner_col, BGI.COPY_PUT, BGI.SOLID_LINE, 1);
      this.bgi.line(x1, y2-1, x1+clen, y2-clen-1, corner_col, BGI.COPY_PUT, BGI.SOLID_LINE, 1);
      this.bgi.line(x2-1, y2-1, x2-clen-1, y2-clen-1, corner_col, BGI.COPY_PUT, BGI.SOLID_LINE, 1);

      this.bgi.popState();
    }
  }


  // Implements RIP_MOUSE
  // clk: 1=invert, 0=don't invert.
  // clr: 1= zoom text window to full screen, and clear.
  // text: host command that gets sent when clicked.
  //
  createMouseRegion (x1, y1, x2, y2, clk, clr, text) {

    let button = {};
    button.isButton = false;
    button.cmdText = text;
    button.invertFlag = (clk === 1) ? true : false;
    button.resetFlag = (clr === 1) ? true : false;
    button.zoomFlag = (clr === 1) ? true : false;

    // TODO: use this.bgi.getviewsettings()
    let vp = this.bgi.info.vp;
    button.ax1 = vp.left + x1;
    button.ay1 = vp.top + y1;
    button.ax2 = vp.left + x2 - 1;
    button.ay2 = vp.top + y2 - 1;

    this.buttons.unshift(button);
  }

  clearAllButtons () {
    this.activateMouseEvents(false);
    this.buttons = [];
  }

  // Implements RIP_BUTTON & RIP_BUTTON_STYLE
  // uses this.bgi, this.clipboard, and modifies this.buttonStyle
  //
  async createButton (x1, y1, x2, y2, hotkey, flags, text, bstyle = this.buttonStyle) {

    if (typeof text !== "string") { return } // TEST
    const [icon_name, label_text, host_cmd] = text.split("<>");
    const bevsize = (bstyle.flags & 512) ? ((bstyle.bevsize > 0) ? bstyle.bevsize : 1) : 0;

    let button = {};
    button.isButton = true;
    button.style = JSON.parse(JSON.stringify(bstyle));
    button.hotkey = hotkey;
    button.flags = flags;
    button.text = text;
    button.cmdText = host_cmd;
    button.invertFlag = (bstyle.flags & 2) ? true : false;
    button.resetFlag = (bstyle.flags & 4) ? true : false; // RIP_RESET_WINDOWS
    button.zoomFlag = (bstyle.flags2 & 4) ? true : false;

    // save current font
    button.textStyle = this.bgi.gettextsettings();

    // include image if Icon button
    if (bstyle.flags & 128) {
      const fname = this.fixIconFilename(icon_name);
      if (fname) {
        button.image = await this.bgi.readimagefile(fname);
      }
    }

    // include image if Clipboard button
    if ((bstyle.flags & 1) && ('width' in this.clipboard) && ('height' in this.clipboard)) {
      button.image = JSON.parse(JSON.stringify(this.clipboard));
    }

    // apply button style dimensions if specified (matches drawButton logic)
    if (bstyle.wid && (bstyle.wid > 0) && bstyle.hgt && (bstyle.hgt > 0)) {
      x2 = x1 + bstyle.wid;
      y2 = y1 + bstyle.hgt;
    }

    // resize if there's an image (overrides style dimensions)
    if (button.image && ('width' in button.image) && ('height' in button.image)) {
      x2 = x1 + button.image.width;
      y2 = y1 + button.image.height;
    }

    // include Hot Icon
    if (bstyle.flags & 4096) {
      const hotname = this.hotIconFilename(icon_name);
      if (hotname) {
        button.hoticon = await this.bgi.readimagefile(hotname);
      }
    }

    // TODO: use this.bgi.getviewsettings()
    let vp = this.bgi.info.vp;
    button.ax1 = vp.left + x1 - bevsize;
    button.ay1 = vp.top + y1 - bevsize;
    button.ax2 = vp.left + x2 + bevsize;
    button.ay2 = vp.top + y2 + bevsize;

    // send host command immediately if pre-selected in a Radio or Checkbox group
    if ((button.flags & 1) && ((bstyle.flags & 16384) || (bstyle.flags2 & 1))) {
      // the spec contradicts itself on this matter, so disabling this for now
      //this.sendHostCommand(host_cmd);
    }

    // only add to list if it's a Mouse Button, and it's a valid button
    if ((bstyle.flags & 1024) && (bstyle.flags & (1 + 128 + 256))) {
      this.buttons.unshift(button);
    }
    return button;
  }

  // Transform a palette color for button highlighting.
  // Reverse-engineered from RIPTERM.EXE FUN_1c6b_0b85 (Ghidra analysis).
  //
  // flags bit 0: Invert color (XOR with 0xF)
  // flags bit 1: Flip brightness (0-7 dark ↔ 8-15 bright)
  //
  // Examples: (0=black, flags=1) → 15=white
  //           (1=blue,  flags=2) → 9=light blue
  //           (5=magenta, flags=3) → 2=green (invert + flip)
  //
  transformButtonColor (color, flags = 1) {
    if (flags & 1) {
      color = (~color) & 0xf;
    }
    if (flags & 2) {
      color = (color < 8) ? color + 8 : color - 8;
    }
    return color;
  }

  // Draws an XOR'd white rectangle for button inversion.
  // This is equivalent to applying transformButtonColor with flags=1
  // to every pixel in the region (since palette XOR with 0xF = invert).
  // (doesn't work in SVG)
  //
  drawInvertedBar (x1, y1, x2, y2) {
    this.bgi._bar(x1, y1, x2-1, y2-1, BGI.WHITE, BGI.XOR_PUT, BGI.SOLID_FILL);
  }

  // Only draws a button without saving anything.
  //
  // properties contained in 'button' obj:
  // hotkey: ASCII value of key to press.
  // flags:
  //   1 = draw as selected.
  //   2 = default when ENTER pressed. (ignore here)
  // text:
  //   ICONFILE[.ICN]<>TEXT LABEL<>HOST COMMAND
  // textStyle: (optional)
  //   { font: 0, direction: 0, charsize: 0 }
  //
  drawButton (x1, y1, x2, y2, button, isSelected = false) {

    const bstyle = button.style;
    const textStyle = button.textStyle;

    if ((bstyle && ('flags' in bstyle) && ((bstyle.flags & (1 + 128 + 256)) !== 0)) === false) {
      // button must be a Clipboard (1), Icon (128), or Plain button (256), else exit
      this.log('rip', "Can't draw invalid button.");
      return;
    }

    const text = button.text || '';
    const [icon_name, label_text, host_cmd] = text.split("<>");
    const bevsize = (bstyle.flags & 512) ? ((bstyle.bevsize > 0) ? bstyle.bevsize : 1) : 0;
    let dback = bstyle.dback;
    let dfore = bstyle.dfore;
    let uline_col = bstyle.uline_col;
    let down = 0, clip_down = 0, isInverted = false;
    if (button.flags & 1) { isSelected = true; }

    //this.log('rip', `${label_text.padStart(10, "_")} : ${bstyle.flags.toString(2).padStart(16, "0")} : ${bstyle.flags2.toString(2).padStart(8, "0")}`); // DEBUG

    // set actual size
    let left = x1, top = y1, right = x2, bot = y2;
    if (bstyle.wid && (bstyle.wid > 0) && bstyle.hgt && (bstyle.hgt > 0)) {
      // style overrides button width & height
      right = left + bstyle.wid; // don't -1!
      bot = top + bstyle.hgt;
    }
    if (('image' in button) && ('width' in button.image) && ('height' in button.image)) {
      // clipboard or icon image overrides button width & height
      right = left + button.image.width;
      bot = top + button.image.height;
      clip_down = 1; // fix to move labels down by 1px (not in spec)
    }

    this.bgi.pushState();
    this.bgi.setlinestyle(BGI.SOLID_LINE);
    this.bgi.setfillstyle(BGI.SOLID_FILL, bstyle.surface);

    // restore text style used when button created
    if (textStyle && ('font' in textStyle) && ('direction' in textStyle) && ('charsize' in textStyle)) {
      this.bgi.settextstyle(textStyle.font, textStyle.direction, textStyle.charsize);
    }

    // prepare if selected
    if (isSelected) {
      // invert normal & highlight text if not center orientation
      // Uses RIPTERM's FUN_1c6b_0b85 color transform (flag 1 = invert via XOR 0xF).
      // Flag 2 (brightness flip) also exists in RIPTERM but the triggering
      // button style bits haven't been identified yet.
      if (bstyle.orient != 2) {
        dback = this.transformButtonColor(dback);
        dfore = this.transformButtonColor(dfore);
        uline_col = this.transformButtonColor(uline_col);
      }
      if (this.opts.origButtons === true) {
        isInverted = true;
      }
      else {
        // the alt button rendering shifts by 1 pixel down
        down = 1;
      }
    }

    // draw bevel (+/- bevsize outside)
    if (bevsize > 0) {
      if (isSelected && (this.opts.origButtons === false)) {
        // draw improved selected style: reverse bright & dark colors & shift down
        this.drawBeveledBox(left - bevsize, top - bevsize + 1, right + bevsize, bot + bevsize,
          bstyle.dark, bstyle.bright, bstyle.corner_col, bevsize);
      }
      else {
        // draw not selected
        this.drawBeveledBox(left - bevsize, top - bevsize + 1, right + bevsize, bot + bevsize,
          bstyle.bright, bstyle.dark, bstyle.corner_col, bevsize);
      }
    }

    // draw 1px recess (+/- 2 outside)
    if (bstyle.flags & 16) {
      // draw 1px recess
      this.bgi.line(left-bevsize-1, top-bevsize-2, right+bevsize, top-bevsize-2, bstyle.dark, BGI.COPY_PUT);
      this.bgi.line(left-bevsize-2, top-bevsize-1, left-bevsize-2, bot+bevsize, bstyle.dark, BGI.COPY_PUT);
      this.bgi.line(right+bevsize+1, top-bevsize-1, right+bevsize+1, bot+bevsize, bstyle.bright, BGI.COPY_PUT);
      this.bgi.line(left-bevsize-1, bot+bevsize+1, right+bevsize, bot+bevsize+1, bstyle.bright, BGI.COPY_PUT);
      // draw corner dots
      this.bgi.putpixel(left-bevsize-2, top-bevsize-2, bstyle.corner_col, BGI.COPY_PUT);
      this.bgi.putpixel(left-bevsize-2, bot+bevsize+1, bstyle.corner_col, BGI.COPY_PUT);
      this.bgi.putpixel(right+bevsize+1, top-bevsize-2, bstyle.corner_col, BGI.COPY_PUT);
      this.bgi.putpixel(right+bevsize+1, bot+bevsize+1, bstyle.corner_col, BGI.COPY_PUT);
      // draw 1px black box outline
      this.bgi.rectangle(left - bevsize - 1, top - bevsize - 1, right + bevsize, bot + bevsize, BGI.BLACK, BGI.COPY_PUT);
    }

    // draw image if present
    if ('image' in button) {
      this.bgi.putimage(left, top, button.image, BGI.COPY_PUT);
    }
    else {
      // draw fill surface
      this.bgi.bar(left, top, right-1, bot-1, bstyle.surface, BGI.COPY_PUT, BGI.SOLID_FILL);
    }

    // draw sunken (inside)
    if (bstyle.flags & 32768) {
      // draw 1px recess
      this.bgi.line(left, top, right-1, top, bstyle.dark, BGI.COPY_PUT);
      this.bgi.line(left, top, left, bot-1, bstyle.dark, BGI.COPY_PUT);
      this.bgi.line(right-1, top, right-1, bot-1, bstyle.bright, BGI.COPY_PUT);
      this.bgi.line(left, bot-1, right-1, bot-1, bstyle.bright, BGI.COPY_PUT);
      // draw corner dots
      this.bgi.putpixel(left, top, bstyle.corner_col, BGI.COPY_PUT);
      this.bgi.putpixel(right-1, top, bstyle.corner_col, BGI.COPY_PUT);
      this.bgi.putpixel(left, bot-1, bstyle.corner_col, BGI.COPY_PUT);
      this.bgi.putpixel(right-1, bot-1, bstyle.corner_col, BGI.COPY_PUT);
    }

    // draw chisel on top of button image (inside)
    if (bstyle.flags & 8) {
      // position depends on button height and a lookup table of indent values
      let xin, yin, h = bot - top; // don't +1!
      if (h < 12) { xin = 1; yin = 1; }
      else if (h < 25) { xin = 3; yin = 2; }
      else if (h < 40) { xin = 4; yin = 3; }
      else if (h < 75) { xin = 6; yin = 5; }
      else if (h < 150) { xin = 7; yin = 5; }
      else if (h < 200) { xin = 8; yin = 6; }
      else if (h < 250) { xin = 10; yin = 7; }
      else if (h < 300) { xin = 11; yin = 8; }
      else { xin = 13; yin = 9; }

      // draw chisel as 2 rectangles
      // including pixels at top-right & bottom-left
      this.bgi.line(left + xin + down, top + yin + down, right - xin - 1 + down, top + yin + down, bstyle.dark, BGI.COPY_PUT);
      this.bgi.line(right - xin - 2 + down, top + yin + down, right - xin - 2 + down, bot - yin - 2 + down, bstyle.dark, BGI.COPY_PUT);
      this.bgi.line(right - xin - 2 + down, bot - yin - 2 + down, left + xin + down, bot - yin - 2 + down, bstyle.dark, BGI.COPY_PUT);
      this.bgi.line(left + xin + down, bot - yin - 1 + down, left + xin + down, top + yin + down, bstyle.dark, BGI.COPY_PUT);
      this.bgi.rectangle(left + xin + 1 + down, top + yin + 1 + down, right - xin - 1 + down, bot - yin - 1 + down, bstyle.bright, BGI.COPY_PUT);
    }

    // auto-stamp image onto clipboard
    // TODO: according to spec, this flag modifies future buttons! (see spec)
    if (bstyle.flags & 64) {
      // RipTerm offsets the label by 1px down (not in spec)
      clip_down = 1;
      // TODO: not sure if coords are correct (need to test with recess)
      this.clipboard = this.bgi._getimage(left - bevsize, top - bevsize, right + bevsize - 1, bot + bevsize - 1);
      this.log('rip', 'Auto-stamped button image onto Clipboard.');
      // clear auto-stamp flag so that clipboard save only occurs once
      // TODO: may rethink this? could store in another var instead of clearing flag
      //bstyle.flags = (bstyle.flags & ~64);
    }

    // calculate label text position
    let tw = this.bgi.textwidth(label_text);
    let th = 0;
    let var_h = this.bgi.textheight(label_text, BGI.VAR_HEIGHT); // VAR_HEIGHT, FULL_HEIGHT
    let main_h = this.bgi.textheight(label_text, BGI.MAIN_HEIGHT);
    let above_h = this.bgi.textheight(label_text, BGI.ABOVE_HEIGHT);
    let label_h = main_h;

    //console.log({ left, top, right, bot, orient: bstyle.orient }); // DEBUG
    //console.log({ var_h, main_h, above_h }); // DEBUG
    //console.log({ tw, th }); // DEBUG

    // start with button center
    let tx = left + Math.floor((right - left) / 2);
    let ty = top + Math.floor((bot - top) / 2) + 1 + clip_down;

    //console.log({ tx, ty }); // DEBUG

    // adjust vertical centering of label
    if (bstyle.flags & 8192) {
      // when set, add descenders to height, except when orient = LEFT or RIGHT.
      label_h = var_h;
      //this.log('rip', `adjust vert: ${label_text} main_h=${main_h} var_h=${var_h} above_h=${above_h}`); // DEBUG
    }

    //console.log({ tw, th }); // DEBUG

    // position using orientation
    switch (bstyle.orient) {
      case 0: // above (TODO)
        th += label_h;
        tx -= Math.floor(tw / 2);
        ty = top - th - th + 2; // - bevsize // ??
        break;
      case 1: // left (TODO)
        th += main_h + (main_h - var_h);
        tx = left - bevsize - tw - 8; // ??
        ty -= Math.round(th / 2) + above_h - 1;
        break;
      case 2: // center (testing...)
        // sometimes off by 1 in x or y.
        th += label_h;
        tx -= Math.round(tw / 2) - down; // or floor ?
        ty -= Math.round(th / 2) + above_h - down;
        break;
      case 3: // right (testing...)
        th += main_h + (main_h - var_h);
        tx = right + bevsize + 7; // TODO: replace (+7) constant!?
        ty -= Math.round(th / 2) + above_h - 1;
        break;
      case 4: // below (TODO)
        tx -= Math.ceil(tw / 2); // -1 ??
        ty = bot - 2; // + bevsize // ??
        break;
      default:
    }

    // reposition if left or right aligned
    // TODO: need to consider non-center buttons
    if (bstyle.flags2 & 8) {
      // left-justified
      tx = left + 7; // ?? TODO: increase if chisel is ON?
    }
    else if (bstyle.flags2 & 16) {
      // right-justified
      tx = right - tw - 7; // ??
    }

    //this.log('rip', `tx=${tx}, ty=${ty}, tw=${tw}, th=${th}, above_h=${above_h}`); // DEBUG

    // draw label dropshadow
    if (bstyle.flags & 32) {
      this.bgi.setcolor(dback);
      this.bgi.outtextxy(tx + 1, ty + 1, label_text);
    }
    // draw label text
    this.bgi.setcolor(dfore);
    this.bgi.outtextxy(tx, ty, label_text);

    // only do if a mouse button,
    // underline or highlight first char in label if flags set
    if ((bstyle.flags & 1024) && ((bstyle.flags & 2048) || (bstyle.flags2 & 2))) {

      // find index of hotkey character
      let hotchar = String.fromCharCode(button.hotkey);
      let idx = label_text.indexOf(hotchar);

      if (idx === 0) {
        // highlight first char again as hotkey
        if (bstyle.flags2 & 2) {
          this.bgi.setcolor(uline_col);
          this.bgi.outtextxy(tx, ty, hotchar);
        }
        // underline first char as hotkey (not perfect)
        if (bstyle.flags & 2048) {
          // chars with descenders have underline drawn slightly lower
          let cvalue = button.hotkey & 0xFF; // clip to 0-255
          let under_yoff = (BGI.charDescenders[cvalue] === 0) ? 2 : 3;
          if (bstyle.flags & 32) {
            // draw dropshadow
            this.bgi.setcolor(dback);
            this.bgi.outtextxy(tx + 1, ty + under_yoff + 1, '_');
          }
          this.bgi.setcolor(uline_col);
          this.bgi.outtextxy(tx, ty + under_yoff, '_');
        }
      }
      else if (idx > 0) {
        // draw prefix text again
        this.bgi.setcolor(dfore);
        this.bgi.outtextxy(tx, ty, label_text.slice(0, idx));

        // highlight hotkey
        if (bstyle.flags2 & 2) {
          // TODO: NOT TESTED
          this.bgi.setcolor(uline_col);
          this.bgi.outtext(hotchar);
        }
        // underline hotkey char (not perfect)
        if (bstyle.flags & 2048) {
          // TODO: NOT TESTED
          // chars with descenders have underline drawn slightly lower
          let cvalue = button.hotkey & 0xFF; // clip to 0-255
          let under_yoff = (BGI.charDescenders[cvalue] === 0) ? 2 : 3;
          let cur_tx = this.bgi.getx;
          let cur_ty = this.bgi.gety;
          if (bstyle.flags & 32) {
            // draw dropshadow
            this.bgi.setcolor(dback);
            this.bgi.outtextxy(cur_tx + 1, cur_ty + under_yoff + 1, '_');
          }
          this.bgi.setcolor(uline_col);
          this.bgi.outtextxy(cur_tx, cur_ty + under_yoff, '_');
        }
      }
    }

    // inverts button if drawing as selected
    if (isInverted) {
      this.drawInvertedBar(left - bevsize, top - bevsize, right + bevsize, bot + bevsize);
    }

    // draw hot icon if present
    // (must be here since bevel could invert)
    if (isSelected && ('hoticon' in button)) {
      this.bgi._putimage(left, top, button.hoticon, BGI.COPY_PUT);
    }

    this.bgi.popState();
  }


  ////////////////////////////////////////////////////////////////////////////////
  // RIP commands

  initCommands () {
    this.cmd = {

      // RIP_TEXT_WINDOW (w)
      'w': (args) => {
        const outer = this;
        let o = { func: 'RIP_TEXT_WINDOW', ...this.parseRIPargs2(args, '222211', ['x0','y0','x1','y1','wrap','size']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            let px, py, pw, ph, textW, textH;
            const wordWrap = this.wrap !== 0;
            const font = RIPterm.FONT_DIMS[this.size & 0x0f] || RIPterm.FONT_DIMS[0];

            // Per RIP spec, x0=y0=x1=y1=0 means "invisible text window"
            if (this.x0 === 0 && this.y0 === 0 && this.x1 === 0 && this.y1 === 0) {
              px = py = pw = ph = textW = textH = 0;
            } else {
              // Per RIP spec, x1/y1 are inclusive lower-right corners
              // Width = x1 - x0 + 1, Height = y1 - y0 + 1
              textW = this.x1 - this.x0 + 1;
              textH = this.y1 - this.y0 + 1;
              px = this.x0 * font.w;
              py = this.y0 * font.h;
              pw = textW * font.w;
              ph = textH * font.h;
            }

            // highlight: draw a box around it
            if (ob.hilite) {
              outer.bgi._fillrect(px, py, px + pw, py + ph);
              return;
            }

            // Store current text window state
            let twindow = { x: px, y: py, width: pw, height: ph, wordWrap, fontnum: this.size,
                            textX: this.x0, textY: this.y0, textW: textW, textH: textH,
                            fontW: font.w, fontH: font.h };

            // Set cursor position to upper-left corner, except when window identical to prior
            if (!(outer.textWindow && (outer.textWindow.x === px) && (outer.textWindow.y === py) &&
              (outer.textWindow.width === pw) && (outer.textWindow.height === ph))) {
              twindow.cp = { row: 1, col: 1 };
            }
            outer.textWindow = twindow;

            // Emit event for external listeners (e.g. BBS client overlays)
            if (outer.onTextWindow) {
              outer.onTextWindow(outer.textWindow);
            }
            outer.log('rip', `TEXT_WINDOW x=${px} y=${py} w=${pw} h=${ph} size=${this.size} wrap=${wordWrap} font=${font.w}x${font.h}`);
          };
        }
        return o;
      },

      // RIP_VIEWPORT (v)
      'v': (args) => {
        const outer = this;
        let o = { func: 'RIP_VIEWPORT', ...this.parseRIPargs2(args, '2222', ['x0','y0','x1','y1']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) {
              outer.bgi._resetViewport();
              outer.bgi._fillrect(this.x0, this.y0, this.x1, this.y1);
            } else {
              outer.bgi.setviewport(this.x0, this.y0, this.x1, this.y1, true);
            }
          };
          //this.log('rip', `!|v${args} RIP_VIEWPORT ${JSON.stringify(o)}`); // DEBUG
        }
        return o;
      },

      // RIP_RESET_WINDOWS (*)
      //   full text window 80x43, cursor to upper-left, clear screen, viewport to fullscreen
      //   fill with current bgcolor, clear mouse regions, clear clipboard, restore default palette
      '*': (args) => {
        const outer = this;
        let o = { func: 'RIP_RESET_WINDOWS' };
        o.run = async function(ob = {}) {
          if (ob.hilite) { return }
          // don't reset colors & styles!
          outer.bgi.cleardevice();
          outer.clearAllButtons();
          outer.clipboard = {};

          // Reset text window to full screen (80x43 text cells)
          outer.textWindow = { x: 0, y: 0, width: 640, height: 350, wordWrap: false, fontnum: 0,
                               textX: 0, textY: 0, textW: 80, textH: 43, fontW: 8, fontH: 8 };
          outer.textWindow.cp = { row: 1, col: 1 };

          // Emit event for external listeners
          if (outer.onTextWindow) {
            outer.onTextWindow(outer.textWindow);
          }
          // TODO: restore default palette
          // TODO: clear text window?
        };
        return o;
      },

      // RIP_ERASE_WINDOW (e)
      // Clears Text Window to background color

      // RIP_ERASE_VIEW (E)
      'E': (args) => {
        const outer = this;
        let o = { func: 'RIP_ERASE_VIEW' };
        o.run = async function(ob = {}) {
          if (ob.hilite) { return }
          outer.bgi.clearviewport();
        };
        return o;
      },

      // RIP_GOTOXY (g)
      // RIP_HOME (H)
      // RIP_ERASE_EOL (>)

      // RIP_COLOR (c)
      'c': (args) => {
        const outer = this;
        let o = { func: 'RIP_COLOR', ...this.parseRIPargs2(args, '2', ['color']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.setcolor(this.color);
          };
        }
        return o;
      },

      // RIP_SET_PALETTE (Q)
      'Q': (args) => {
        // parse EGA palette values (0-63)
        const outer = this;
        let o = { func: 'RIP_SET_PALETTE' };
        o.palette = this.parseRIPargs(args, '2222222222222222');
        if (this.noNaNs(o.palette)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            for (let i=0; i < this.palette.length; i++) {
              const c = this.palette[i];
              if (c < 64) {
                const [red, green, blue] = outer.hex2rgb( RIPterm.paletteEGA64[c] );
                outer.bgi.setrgbpalette(i, red, green, blue);
              }
            }
          };
        }
        return o;
      },

      // RIP_ONE_PALETTE (a)
      'a': (args) => {
        // parse EGA palette values (0-63)
        const outer = this;
        let o = { func: 'RIP_ONE_PALETTE', ...this.parseRIPargs2(args, '22', ['color','value']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            if ((this.color < 16) && (this.value < 64)) {
              const [red, green, blue] = outer.hex2rgb( RIPterm.paletteEGA64[this.value] );
              outer.bgi.setrgbpalette(this.color, red, green, blue);
            }
          };
        }
        return o;
      },

      // RIP_WRITE_MODE (W)
      'W': (args) => {
        const outer = this;
        let o = { func: 'RIP_WRITE_MODE', ...this.parseRIPargs2(args, '2', ['mode']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.setwritemode(this.mode);
          };
        }
        return o;
      },

      // RIP_MOVE (m)
      'm': (args) => {
        const outer = this;
        let o = { func: 'RIP_MOVE', ...this.parseRIPargs2(args, '22', ['x','y']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.moveto(this.x, this.y);
          };
        }
        return o;
      },

      // RIP_TEXT (T)
      // uses and updates info.cp
      'T': (args) => {
        const outer = this;
        let o = { func: 'RIP_TEXT', ...this.parseRIPargs2(args, '*', ['text']) };
        o.run = async function(ob = {}) {
          if (ob.hilite) { return }
          outer.bgi.outtext(this.text);
        };
        return o;
      },

      // RIP_TEXT_XY (@)
      // updates info.cp
      '@': (args) => {
        const outer = this;
        let o = { func: 'RIP_TEXT_XY', ...this.parseRIPargs2(args, '22*', ['x','y','text']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.outtextxy(this.x, this.y, this.text);
            //outer.log('rip', 'RIP_TEXT_XY: ' + this.text); // DEBUG
          };
        }
        return o;
      },

      // RIP_FONT_STYLE (Y)
      'Y': (args) => {
        const outer = this;
        let o = { func: 'RIP_FONT_STYLE', ...this.parseRIPargs2(args, '2222', ['font','direction','size','res']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.settextstyle(this.font, this.direction, this.size);
          };
        }
        return o;
      },

      // RIP_PIXEL (X)
      // spec says this doesn't use writeMode (mistake?)
      'X': (args) => {
        const outer = this;
        let o = { func: 'RIP_PIXEL', ...this.parseRIPargs2(args, '22', ['x','y']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            outer.bgi.putpixel(this.x, this.y); // uses writeMode
          };
        }
        return o;
      },

      // RIP_LINE (L)
      'L': (args) => {
        const outer = this;
        let o = { func: 'RIP_LINE', ...this.parseRIPargs2(args, '2222', ['x0','y0','x1','y1']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            outer.bgi.line(this.x0, this.y0, this.x1, this.y1);
          };
        }
        return o;
      },

      // RIP_RECTANGLE (R)
      'R': (args) => {
        const outer = this;
        let o = { func: 'RIP_RECTANGLE', ...this.parseRIPargs2(args, '2222', ['x0','y0','x1','y1']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            outer.bgi.rectangle(this.x0, this.y0, this.x1, this.y1);
          };
        }
        return o;
      },

      // RIP_BAR (B)
      // Fills a rectangle using fill color and pattern, without border.
      // spec says this doesn't use writeMode (mistake?)
      'B': (args) => {
        const outer = this;
        let o = { func: 'RIP_BAR', ...this.parseRIPargs2(args, '2222', ['x0','y0','x1','y1']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            outer.bgi.bar(this.x0, this.y0, this.x1, this.y1); // uses writeMode
          };
        }
        return o;
      },

      // RIP_CIRCLE (C)
      'C': (args) => {
        const outer = this;
        let o = { func: 'RIP_CIRCLE', ...this.parseRIPargs2(args, '222', ['x','y','radius']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            outer.bgi.circle(this.x, this.y, this.radius);
          };
        }
        return o;
      },

      // RIP_OVAL (O)
      // does same as RIP_OVAL_ARC (V)
      // weird that this includes start & end angles
      'O': (args) => {
        let o = this.cmd['V'](args);
        if (o) {
          o.func = 'RIP_OVAL';
          return o;
        }
      },

      // RIP_FILLED_OVAL (o)
      'o': (args) => {
        const outer = this;
        let o = { func: 'RIP_FILLED_OVAL', ...this.parseRIPargs2(args, '2222', ['x','y','x_rad','y_rad']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            outer.bgi.fillellipse(this.x, this.y, this.x_rad, this.y_rad);
            outer.bgi.ellipse(this.x, this.y, 0, 360, this.x_rad, this.y_rad); // may be included in fillellipse() ?
          };
        }
        return o;
      },

      // RIP_ARC (A)
      'A': (args) => {
        const outer = this;
        let o = { func: 'RIP_ARC', ...this.parseRIPargs2(args, '22222', ['x','y','start_ang','end_ang','radius']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            outer.bgi.arc(this.x, this.y, this.start_ang, this.end_ang, this.radius);
          };
        }
        return o;
      },

      // RIP_OVAL_ARC (V)
      // does same as RIP_OVAL (O)
      'V': (args) => {
        const outer = this;
        let o = { func: 'RIP_OVAL_ARC', ...this.parseRIPargs2(args, '222222', ['x','y','st_ang','e_ang','radx','rady']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            outer.bgi.ellipse(this.x, this.y, this.st_ang, this.e_ang, this.radx, this.rady);
          };
        }
        return o;
      },

      // RIP_PIE_SLICE (I)
      'I': (args) => {
        const outer = this;
        let o = { func: 'RIP_PIE_SLICE', ...this.parseRIPargs2(args, '22222', ['x','y','start_ang','end_ang','radius']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) {
              // temp highlight: draw a box around it
              outer.bgi._fillrect(this.x-this.radius, this.y-this.radius, this.x+this.radius, this.y+this.radius);
            } else {
              outer.bgi.pieslice(this.x, this.y, this.start_ang, this.end_ang, this.radius);
            }
          };
        }
        return o;
      },

      // RIP_OVAL_PIE_SLICE (i)
      'i': (args) => {
        const outer = this;
        let o = { func: 'RIP_OVAL_PIE_SLICE', ...this.parseRIPargs2(args, '222222', ['x','y','st_ang','e_ang','radx','rady']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) {
              // temp highlight: draw a box around it
              outer.bgi._fillrect(this.x-this.radx, this.y-this.rady, this.x+this.radx, this.y+this.rady);
            } else {
              outer.bgi.sector(this.x, this.y, this.st_ang, this.e_ang, this.radx, this.rady);
            }
          };
        }
        return o;
      },

      // RIP_BEZIER (Z)
      'Z': (args) => {
        const outer = this;
        let o = { func: 'RIP_BEZIER' };
        let points = this.parseRIPargs(args, '222222222'); // 9 ints
        o.cnt = points.pop();
        o.points = points;
        if (!Number.isNaN(o.cnt) && this.noNaNs(points)) {
          o.run = async function(ob = {}) {
            outer.bgi.drawbezier(this.cnt, this.points);
          };
        }
        return o;
      },

      // RIP_POLYGON (P)
      'P': (args) => {
        const outer = this;
        let pp = this.parseRIPpoly(args);
        let npoints = pp.shift();
        let o = { func: 'RIP_POLYGON', npoints, pp };
        if (!Number.isNaN(npoints) && this.noNaNs(pp)) {
          o.run = async function(ob = {}) {
            outer.bgi.drawpoly(this.npoints, this.pp);
          };
        }
        return o;
      },

      // RIP_FILL_POLYGON (p)
      'p': (args) => {
        const outer = this;
        let pp = this.parseRIPpoly(args);
        let npoints = pp.shift();
        // draw both a filled polygon using fill color & bgcolor,
        // and polygon outline using fgcolor, line style, and thickness.
        let o = { func: 'RIP_FILL_POLYGON', npoints, pp };
        if (!Number.isNaN(npoints) && this.noNaNs(pp)) {
          o.run = async function(ob = {}) {
            outer.bgi.fillpoly(this.npoints, this.pp);
          };
        }
        return o;
      },

      // RIP_POLYLINE (l)
      // this was introduced in RIPscrip v1.54 (not present in v1.52)
      'l': (args) => {
        const outer = this;
        let pp = this.parseRIPpoly(args);
        let npoints = pp.shift();
        let o = { func: 'RIP_POLYLINE', npoints, pp };
        if (!Number.isNaN(npoints) && this.noNaNs(pp)) {
          o.run = async function(ob = {}) {
            outer.bgi.drawpolyline(this.npoints, this.pp);
          };
        }
        return o;
      },

      // RIP_FILL (F)
      'F': (args) => {
        const outer = this;
        let o = { func: 'RIP_FILL', ...this.parseRIPargs2(args, '222', ['x','y','border']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.floodfill(this.x, this.y, this.border);
          };
        }
        return o;
      },

      // RIP_LINE_STYLE (=)
      // pre-defined styles introduced in RIPscrip v1.54 (only custom-defined in v1.52 ??)
      '=': (args) => {
        const outer = this;
        let o = { func: 'RIP_LINE_STYLE', ...this.parseRIPargs2(args, '242', ['style','user_pat','thick']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.setlinestyle(this.style, this.user_pat, this.thick);
          };
        }
        return o;
      },

      // RIP_FILL_STYLE (S)
      'S': (args) => {
        const outer = this;
        let o = { func: 'RIP_FILL_STYLE', ...this.parseRIPargs2(args, '22', ['pattern','color']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.setfillstyle(this.pattern, this.color);
          };
        }
        return o;
      },

      // RIP_FILL_PATTERN (s)
      's': (args) => {
        const outer = this;
        let o = { func: 'RIP_FILL_PATTERN', ...this.parseRIPargs2(args, '222222222', ['c1','c2','c3','c4','c5','c6','c7','c8','color']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.setfillpattern([this.c1, this.c2, this.c3, this.c4, this.c5, this.c6, this.c7, this.c8], this.color);
          };
        }
        return o;
      },

      // RIP_MOUSE (1M)
      '1M': (args) => {
        const outer = this;
        let o = { func: 'RIP_MOUSE', ...this.parseRIPargs2(args, '22222115*', ['num','x0','y0','x1','y1','clk','clr','res','text']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) {
              outer.bgi._fillrect(this.x0, this.y0, this.x1, this.y1);
            } else {
              outer.createMouseRegion(this.x0, this.y0, this.x1, this.y1, this.clk, this.clr, this.text);
            }
          };
        }
        return o;
      },

      // RIP_KILL_MOUSE_FIELDS (1K)
      '1K': (args) => {
        const outer = this;
        let o = { func: 'RIP_KILL_MOUSE_FIELDS' };
        o.run = async function(ob = {}) {
          if (ob.hilite) { return }
          outer.clearAllButtons();
        };
        return o;
      },

      // RIP_BEGIN_TEXT (1T)
      // RIP_REGION_TEXT (1t)
      // RIP_END_TEXT (1R)

      // RIP_GET_IMAGE (1C)
      '1C': (args) => {
        const outer = this;
        let o = { func: 'RIP_GET_IMAGE', ...this.parseRIPargs2(args, '22221', ['x0','y0','x1','y1','res']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) {
              outer.bgi._fillrect(this.x0, this.y0, this.x1, this.y1);
            } else {
              outer.clipboard = outer.bgi.getimage(this.x0, this.y0, this.x1, this.y1);
            }
          };
        }
        return o;
      },

      // RIP_PUT_IMAGE (1P)
      '1P': (args) => {
        const outer = this;
        let o = { func: 'RIP_PUT_IMAGE', ...this.parseRIPargs2(args, '2221', ['x','y','mode','res']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.bgi.putimage(this.x, this.y, outer.clipboard, this.mode);
          };
        }
        return o;
      },

      // RIP_WRITE_ICON (1W)

      // RIP_LOAD_ICON (1I)
      '1I': (args) => {
        const outer = this;
        let o = { func: 'RIP_LOAD_ICON', ...this.parseRIPargs2(args, '22212*', ['x','y','mode','clipflag','res','filename']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            const fname = outer.fixIconFilename(this.filename);
            if (fname) {
              if (ob.hilite) {
                // draw an inverted box for debugging (img should already be cached)
                const img = await outer.bgi.readimagefile(fname); // keep here
                if (img && img.data) {
                  outer.bgi._fillrect(this.x, this.y, this.x + img.width, this.y + img.height);
                }
              } else {
                outer.log('rip', 'RIP_LOAD_ICON: ' + fname);
                const img = await outer.bgi.readimagefile(fname);
                outer.bgi.putimage(this.x, this.y, img, this.mode);
                if (this.clipflag === 1) {
                  outer.clipboard = img;
                }
              }
            }
          };
        }
        return o;
      },

      // RIP_BUTTON_STYLE (1B)
      '1B': (args) => {
        const outer = this;
        let o = { func: 'RIP_BUTTON_STYLE', ...this.parseRIPargs2(args, '22242222222222', ['wid','hgt','orient','flags','bevsize','dfore','dback','bright','dark','surface','grp_no','flags2','uline_col','corner_col']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            outer.buttonStyle = this; // doesn't need .func or .run
          };
        }
        return o;
      },

      // RIP_BUTTON (1U)
      '1U': (args) => {
        const outer = this;
        let o = { func: 'RIP_BUTTON', ...this.parseRIPargs2(args, '2222211*', ['x0','y0','x1','y1','hotkey','flags','res','text']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) {
              outer.bgi._fillrect(this.x0, this.y0, this.x1, this.y1);
            } else {
              let btn = await outer.createButton(this.x0, this.y0, this.x1, this.y1, this.hotkey, this.flags, this.text);
              outer.drawButton(this.x0, this.y0, this.x1, this.y1, btn, false);
            }
          };
        }
        return o;
      },

      // RIP_DEFINE (1D)
      // RIP_QUERY (1␛)(1<ESC>)
      // RIP_COPY_REGION (1G)
      // RIP_READ_SCENE (1R)
      // RIP_FILE_QUERY (1F)

      // RIP_ENTER_BLOCK_MODE (9␛)(9<ESC>)

      // RIP_HEADER (h) - RIPscrip v2.0
      'h': (args) => {
        const outer = this;
        let o = { func: 'RIP_HEADER', ...this.parseRIPargs2(args, '242', ['revision','flags','res']) };
        if (this.noNaNs(o)) {
          o.run = async function(ob = {}) {
            if (ob.hilite) { return }
            if (this.revision > 0) {
              outer.log('rip', 'RIPscrip 2.0 or above NOT SUPPORTED at this time!');
            }
          };
        }
        return o;
      },

      // RIP_COMMENT (!) v2.0
      // (leave commented out so it shows red)
      //'!': (args) => {
        // do nothing
      //},

      // RIP_NO_MORE (#)
      '#': (args) => {
        // do nothing
        const outer = this;
        let o = { func: 'RIP_NO_MORE' };
        o.run = async function(ob = {}) {
          if (ob.hilite) { return }
          outer.activateMouseEvents(true);
        };
        return o;
      }
    }
  }

} // end class RIPterm
////////////////////////////////////////////////////////////////////////////////

if (typeof window !== 'undefined') {
  window.RIPterm = RIPterm;
}

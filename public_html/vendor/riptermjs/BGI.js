/**
 * BGI.js
 * Copyright (c) 2020-2026 Carl Gorringe
 * https://carl.gorringe.org
 * https://github.com/cgorringe/RIPtermJS
 *
 * Borland Graphics Interface (BGI) for JavaScript
 * https://en.wikipedia.org/wiki/Borland_Graphics_Interface
 * 
 * This is a subset of the library which emulates only some BGI functions.
 *
 * Used source code from:
 * https://github.com/genpfault/sdl-bgi
 * https://github.com/buhtu4ek/OsXBGI
 *
 * BGI Docs:
 * https://www.cs.colorado.edu/~main/bgi/doc/
 * http://math.ubbcluj.ro/~sberinde/wingraph/
 *
 **/

/**
 * This Source Code is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 **/

// not using as a module for now, but
// uncomment this line if you do want to use as a module:
//export default 
class BGI {

  // class static constants moved to end of file.

  // don't want both below...

  // packed ARGB values (0xAARRGGBB) in C++ style
  static get bgi_palette () { return [
    0xFF000000, // 00 BLACK
    0xFF0000AA, // 01 BLUE
    0xFF00AA00, // 02 GREEN
    0xFF00AAAA, // 03 CYAN
    0xFFAA0000, // 04 RED
    0xFFAA00AA, // 05 MAGENTA
    0xFFAA5500, // 06 BROWN
    0xFFAAAAAA, // 07 LIGHTGRAY
    0xFF555555, // 08 DARKGRAY
    0xFF5555FF, // 09 LIGHTBLUE
    0xFF55FF55, // 0A LIGHTGREEN
    0xFF55FFFF, // 0B LIGHTCYAN
    0xFFFF5555, // 0C LIGHTRED
    0xFFFF55FF, // 0D LIGHTMAGENTA
    0xFFFFFF55, // 0E YELLOW
    0xFFFFFFFF  // 0F WHITE
  ]; }

  // RGBA32 format: R, G, B, A
  static get ega_palette () { return [
    0x00, 0x00, 0x00, 0xFF, // 00 BLACK
    0x00, 0x00, 0xAA, 0xFF, // 01 BLUE
    0x00, 0xAA, 0x00, 0xFF, // 02 GREEN
    0x00, 0xAA, 0xAA, 0xFF, // 03 CYAN
    0xAA, 0x00, 0x00, 0xFF, // 04 RED
    0xAA, 0x00, 0xAA, 0xFF, // 05 MAGENTA
    0xAA, 0x55, 0x00, 0xFF, // 06 BROWN
    0xAA, 0xAA, 0xAA, 0xFF, // 07 LIGHTGRAY
    0x55, 0x55, 0x55, 0xFF, // 08 DARKGRAY
    0x55, 0x55, 0xFF, 0xFF, // 09 LIGHTBLUE
    0x55, 0xFF, 0x55, 0xFF, // 0A LIGHTGREEN
    0x55, 0xFF, 0xFF, 0xFF, // 0B LIGHTCYAN
    0xFF, 0x55, 0x55, 0xFF, // 0C LIGHTRED
    0xFF, 0x55, 0xFF, 0xFF, // 0D LIGHTMAGENTA
    0xFF, 0xFF, 0x55, 0xFF, // 0E YELLOW
    0xFF, 0xFF, 0xFF, 0xFF, // 0F WHITE
  ]; }

  static get line_patterns () { return [
    0xFFFF, // 00 SOLID_LINE  : 1111111111111111
    0xCCCC, // 01 DOTTED_LINE : 1100110011001100
    0xFC78, // 02 CENTER_LINE : 1111110001111000
    0xF8F8, // 03 DASHED_LINE : 1111100011111000
    0xFFFF, // 04 USERBIT_LINE
  ]; }

  static get fill_patterns () { return [
    [0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00], // 00 EMPTY_FILL
    [0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF], // 01 SOLID_FILL
    [0xFF, 0xFF, 0x00, 0x00, 0xFF, 0xFF, 0x00, 0x00], // 02 LINE_FILL
    [0x01, 0x02, 0x04, 0x08, 0x10, 0x20, 0x40, 0x80], // 03 LTSLASH_FILL
    [0xE0, 0xC1, 0x83, 0x07, 0x0E, 0x1C, 0x38, 0x70], // 04 SLASH_FILL
    [0xF0, 0x78, 0x3C, 0x1E, 0x0F, 0x87, 0xC3, 0xE1], // 05 BKSLASH_FILL
    [0xA5, 0xD2, 0x69, 0xB4, 0x5A, 0x2D, 0x96, 0x4B], // 06 LTBKSLASH_FILL
    [0xFF, 0x88, 0x88, 0x88, 0xFF, 0x88, 0x88, 0x88], // 07 HATCH_FILL
    [0x81, 0x42, 0x24, 0x18, 0x18, 0x24, 0x42, 0x81], // 08 XHATCH_FILL
    [0xCC, 0x33, 0xCC, 0x33, 0xCC, 0x33, 0xCC, 0x33], // 09 INTERLEAVE_FILL
    [0x80, 0x00, 0x08, 0x00, 0x80, 0x00, 0x08, 0x00], // 0A WIDE_DOT_FILL
    [0x88, 0x00, 0x22, 0x00, 0x88, 0x00, 0x22, 0x00], // 0B CLOSE_DOT_FILL
    [0xAA, 0x55, 0xAA, 0x55, 0xAA, 0x55, 0xAA, 0x55], // 0C USER_FILL / PabloDraw
  ]; }


  ////////////////////////////////////////////////////////////////////////////////
  // Contructor & init methods

  // Pass an object to constructor with these keys:
  //   ctx: CanvasRenderingContext2D() - required
  //   width: int - optional if ctx derived from a <canvas>
  //   height: int - optional if ctx derived from a <canvas>
  //   log: function (type, msg) - optional callback function with 2 arguments.

  constructor (args) {

    // public fields
    this.width = 1;
    this.height = 1;
    this.aspect = { xasp: 1, yasp: 1 }; // use 372, 480 for RipTerm
    this.isBuffered = true; // true = copy pixels to context, false = using context data store
    this.colorMask = 0x0F;  // 0xF = 16-color mode, 0xFF = 256-color mode
    this.fonts = {}; // key is file, e.g. 'GOTH.CHR' (may rethink this later)
    this.mouseX = 0;
    this.mouseY = 0;

    // index is font size: 0=8x8, 1=7x8, 2=8x14, 3=7x14, 4=16x14.
    // value is an array of 256 elements for each ASCII char,
    // each element is an array of ints, each being a scanline of height values.
    // e.g. this.bitfonts[0][65][2] is font size 0, ASCII char 65, 3rd scanline which returns an 8-bit int.
    this.bitfonts = [];
    this.stateStack = [];
    this.icons = {}; // key is uppercased file+ext, e.g. 'EXAMPLE.ICN', value is image obj used in putimage().
    this.svgActive = false;

    // log callback function
    if ('log' in args) {
      this.logFunc = args.log;
    }

    // need to pass this in to find font files
    if ('fontsPath' in args) {
      this.fontsPath = args.fontsPath;
    }

    // need to pass this in to find icon files
    if ('iconsPath' in args) {
      this.iconsPath = args.iconsPath;
    }

    // set to 'window' to bind class methods to global namespace
    if ('root' in args) {
      this.initRoot(args.root);
    }

    this.initContext(args.ctx, args.width, args.height);
    // which assigns these:
    //   this.ctx     : CanvasRenderingContext2D()
    //   this.pixels  : Uint8ClampedArray()
    //   this.imgData : ImageData()
    //   this.width, this.height, this.isBuffered

    this.graphdefaults();
  }

  infoDefaults () {
    return {
      bgcolor: BGI.BLACK,
      fgcolor: BGI.WHITE,
      // need to set fill.fpattern whenever fill.style is set
      fill: { style: BGI.SOLID_FILL, color: BGI.WHITE, fpattern: BGI.fill_patterns[BGI.SOLID_FILL] },
      line: { style: BGI.SOLID_LINE, upattern: 0xFFFF, thickness: 1 },
      cp: { x: 0, y: 0 }, // current position
      vp: { left: 0, top: 0, // viewport
            right: (this.width - 1), bottom: (this.height - 1),
            width: this.width, height: this.height,
            clip: false },
      // ap: { left: 0, top: 0, right: (this.width - 1), bottom: (this.height - 1), clip: 0 }, // active port / page?
      writeMode: 0, // 0=COPY, 1=XOR, 2=OR, 3=AND, 4=NOT
      font: { width: 0, height: 0 }, // ??
      fontMag: { x: 1.0, y: 1.0 }, // font magnification
      text: { font: 0, direction: 0, charsize: 0, horiz: 0, vert: 0 },
    };
  }

  // Initializes BGI with a canvas context. [not in BGI]
  // ctx comes from canvas.getContext('2d').
  // ctx can come from canvas.getContext('2d', { pixelFormat: 'A8' }) if using a node canvas.
  // width & height are optional if ctx comes from a browser canvas.

  /*
    // Example browser code:
    const canvas = document.getElementById('canvas')
    const ctx = canvas.getContext('2d')
    // ctx in RGBA32 format uses Uint8ClampedArray 4 bytes-per-pixel.

    // Example node code:
    const { createCanvas, loadImage } = require('canvas')
    const canvas = createCanvas(640, 350)
    const ctx = canvas.getContext('2d', { pixelFormat: 'A8' })
    // ctx is indexed Uint8ClampedArray 1 byte-per-pixel.
  */

  initContext (ctx, width, height) {

    this.ctx = ctx
    if (width && height) {
      // used provided size
      this.width = Math.floor(width);
      this.height = Math.floor(height);
    }
    else if (ctx && ctx.canvas) {
      // get size from browser canvas
      this.width = Math.floor(ctx.canvas.width);
      this.height = Math.floor(ctx.canvas.height);
    }
    else {
      this.log('err', 'Missing "ctx" canvas context passed to BGI() constructor!');
      return;
    }

    // needed to access context pixel data ??
    //this.imgData = ctx.getImageData(0, 0, this.width, this.height);
    this.imgData = ctx.createImageData(this.width, this.height);

    // Do we need to use this for 'A8' pixel format?
    // this.imgData = new ImageData(new Uint8ClampedArray(size), width, height)

    // check to see if ctx is already using 8-bit indexed pixel data.
    // only works in Chrome & Edge, not Firefox nor Safari.
    if (typeof ctx.getContextAttributes === 'function') {
      let attr = ctx.getContextAttributes();
      this.isBuffered = (attr && (attr.pixelFormat === 'A8')) ? false : true;
      /* && this.imgData && this.imgData.data */
    }
    else {
      this.isBuffered = true;
    }

    if (this.isBuffered) {
      // create a new byte buffer for pixel data, which is usually the case in browsers.
      this.pixels = new Uint8ClampedArray(this.width * this.height)
    }
    else {
      // use existing context data, which may be used in node canvas. [NOT TESTED]
      // does this a reference or a copy?
      this.pixels = this.imgData.data;
    }

    // this pixel buffer is used during flood fill
    this.fillpixels = new Uint8ClampedArray(this.width * this.height)

    // setup mousemove handler to update mouse positions whenever the mouse moves.
    this.registermousehandler(0, (function(x, y) {
      this.mouseX = x;
      this.mouseY = y;
    }).bind(this));
  }

  // Loads all the vector font .CHR files in BGI.fontFileList[]
  async initFonts () {

    // load vector fonts
    await Promise.all(BGI.fontFileList.map(async fname => {
      if (fname.length > 3) {
        this.fonts[fname] = await this.fetchCHRFont(fname);
      }
    }));

    // load bitmap fonts
    // index is font size: 0=8x8, 1=7x8, 2=8x14, 3=7x14, 4=16x14.
    this.bitfonts[0] = await this.fetchPNGFont('8x8.png', 8, 8);
    this.bitfonts[1] = await this.fetchPNGFont('w1-7x8.png', 7, 8);
    this.bitfonts[2] = await this.fetchPNGFont('8x14.png', 8, 14);
    this.bitfonts[3] = await this.fetchPNGFont('w3-7x14.png', 7, 14);
    this.bitfonts[4] = await this.fetchPNGFont('w4-16x14.png', 16, 14);
  }

  // TODO: rethink this or replace
  // int width, int height, const char* title="Windows BGI", int left=0, int top=0, bool dbflag=false, bool closeflag=true
  // return value is an id to later use in setcurrentwindow()
  initwindow (width, height) {

    /*
    // do I need "this" ?
    this._imageData = new ImageData(width, height);
    this.pixels = new Uint8ClampedArray(width * height);

    this.info.max.x = width - 1;
    this.info.max.y = height - 1;
    */

    // don't need these here, as they're reset in graphdefaults()
    //info.vp = { x1:0, y1:0, x2:0, y2:0, clip:0 };
    //info.ap = { x1:0, y1:0, x2:0, y2:0, clip:0 };

    this.graphdefaults();

    return 0;
  }

  // (EXPERIMENTAL)
  // Assign functions to root window so that they may be called directly
  // without needing the object part.
  initRoot (root = window) {

    // TODO: Should rename some methods in BGI class to begin with '_' to exclude from root.
    //       (e.g. 'log' ==> '_log')

    // TODO: Redo BGI constants so that they may be included in root.

    // enumerate all the methods in this class that we want to bind to root
    let proto = Object.getPrototypeOf(this);
    Object.getOwnPropertyNames(proto).forEach(name => {
      if ((name !== 'constructor') && (name !== 'log') && (name.startsWith('_') === false)) {
        root[name] = this[name].bind(this);
      }
    });
  }

  ////////////////////////////////////////////////////////////////////////////////
  // Extra methods

  // sends msg to provided log function, else send to console if none provided.
  log (type, msg) {
    if (typeof this.logFunc === 'function') {
      this.logFunc(type, msg);
    }
    else {
      if (type === 'err') {
        console.error(msg);
      }
      else {
        console.log(msg);
      }
    }
  }

  unimplemented (method) {
    this.log('bgi', method + '() not implemented.');
  }

  // updates the screen
  refresh (defaults = {}) {

    const {
      ctx = this.ctx,
      img = this.imgData,
      pix = this.pixels,
      pal = this.palette,
      isBuffered = this.isBuffered,
    } = defaults;

    if (!(ctx && img && pix && pal)) { return false; }

    // copy pixel buffer to img
    if (isBuffered) {
      // copy 8-bit pixels to 32-bit imgData
      const numpix = pix.length;
      let pi = 0;
      for (let i=0, j=0; i < numpix; i++, j+=4) {
        pi = pix[i] * 4;
        img.data[j + 0] = pal[pi + 0]; // R
        img.data[j + 1] = pal[pi + 1]; // G
        img.data[j + 2] = pal[pi + 2]; // B
        img.data[j + 3] = pal[pi + 3]; // A
      }
    }
    /*
    else {
      // using 8-bit imgData (NOT TESTED)
      let numpix = pix.length;
      for (let i=0; i < numpix; i++) {
        img.data[i] = pix[i];
      }
      // assumes this.pixels point to this.imgData.data
      //ctx.putImageData(this.imgData, 0, 0);
    }
    */
    ctx.putImageData(img, 0, 0);
    return true;
  }

  // conditionally refreshes the screen or schedule it
  update () {
    // TODO
    this.refresh();
  }

  // saves this.info to a stack
  pushState () {
    this.stateStack.push( JSON.parse(JSON.stringify(this.info)) );
  }

  // restores this.info from a stack
  popState () {
    if (this.stateStack.length > 0) {
      this.info = this.stateStack.pop();
    }
  }

  // Rearranges colors in the given palette applying NOT of index.
  // returns a new palette.
  invertedPalette (defaults = {}) {
    const {
      pal = this.palette,
      mask = this.colorMask,
    } = defaults;

    const plength = (mask + 1) * 4;
    let pal2 = Array(plength).fill(0);
    let pi1=0, pi2=0;

    for (let c=0; c <= mask; c++) {
      pi1 = c * 4;
      pi2 = (~c & mask) * 4;
      // RGBA32 format: R, G, B, A (0-255)
      pal2[pi2 + 0] = pal[pi1 + 0];
      pal2[pi2 + 1] = pal[pi1 + 1];
      pal2[pi2 + 2] = pal[pi1 + 2];
      pal2[pi2 + 3] = pal[pi1 + 3];
    }
    return pal2;
  }


  ////////////////////////////////////////////////////////////////////////////////
  // Drawing methods

  // Draws a pixel offset and clipped by current viewport.
  putpixel (x, y, color = this.info.fgcolor, wmode = this.info.writeMode) {
    this._putpixel(x, y, color, wmode);
  }
  _putpixel (x, y, color = this.info.fgcolor, wmode = this.info.writeMode, buf = this.pixels) {

    // should these be using .floor() ?
    x = Math.round(x);
    y = Math.round(y);

    const vp = this.info.vp;
    x += vp.left;
    y += vp.top;

    if (!vp.clip || ((x >= vp.left) && (x <= vp.right) && (y >= vp.top) && (y <= vp.bottom))) {
      this._putpixel_abs(x, y, color, wmode, buf);
    }
  }

  // x,y in absolute coordinates
  _putpixel_abs (x, y, color = this.info.fgcolor, wmode = this.info.writeMode, buf = this.pixels) {
    if ((x >= 0) && (x < this.width) && (y >= 0) && (y < this.height)) {
      switch (wmode) {
        default:
        case BGI.COPY_PUT:
          buf[y * this.width + x] = color;
          break;
        case BGI.XOR_PUT:
          buf[y * this.width + x] ^= color;
          break;
        case BGI.OR_PUT:
          buf[y * this.width + x] |= color;
          break;
        case BGI.AND_PUT:
          buf[y * this.width + x] &= color;
          break;
        case BGI.NOT_PUT:
          buf[y * this.width + x] = (~color & this.colorMask);
      }
    }
  }

  // floodfill putpixel using fill pattern.
  // uses info.fill.color, info.bgcolor, and info.fill.fpattern that must be pre-set.
  // pixel will be offset and clipped by current viewport.
  ff_putpixel (x, y, color = this.info.fill.color, wmode = this.info.writeMode) {

    const bgcolor = this.info.bgcolor;
    const fpattern = this.info.fill.fpattern; // array
    const vp = this.info.vp;
    x += vp.left;
    y += vp.top;

    if (!vp.clip || ((x >= vp.left) && (x <= vp.right) && (y >= vp.top) && (y <= vp.bottom))) {
      // draw pixel if bit in pattern is 1
      if ( (fpattern[y % 8] >> (7 - (x % 8))) & 1) {
        // forground pixel
        this._putpixel_abs(x, y, color, wmode);
      }
      else {
        // background pixel
        this._putpixel_abs(x, y, bgcolor, wmode);
      }
    }
  }

  thick_putpixel (x, y, color = this.info.fgcolor, wmode = this.info.writeMode) {
    // could be more efficient
    //this._putpixel(x-1, y-1, color, wmode);
    this._putpixel(x  , y-1, color, wmode);
    //this._putpixel(x+1, y-1, color, wmode);
    this._putpixel(x-1, y  , color, wmode);
    this._putpixel(x  , y  , color, wmode);
    this._putpixel(x+1, y  , color, wmode);
    //this._putpixel(x-1, y+1, color, wmode);
    this._putpixel(x  , y+1, color, wmode);
    //this._putpixel(x+1, y+1, color, wmode);

    /*
    // TESTing
    this._putpixel(x-1, y-1, color, wmode);
    this._putpixel(x  , y-1, color, wmode);
    this._putpixel(x+1, y-1, color, wmode);
    this._putpixel(x-1, y  , color, wmode);
    //this._putpixel(x  , y  , color, wmode);
    this._putpixel(x+1, y  , color, wmode);
    this._putpixel(x-1, y+1, color, wmode);
    this._putpixel(x  , y+1, color, wmode);
    this._putpixel(x+1, y+1, color, wmode);
    */
  }

  // NOT USED
  circle_bresenham (cx, cy, radius, thickness = this.info.line.thickness) {

    const putpix = (thickness === 3)
      ? (a, b) => this.thick_putpixel(a, b)
      : (a, b) => this._putpixel(a, b);
    let xx = -radius, yy = 0, err = 2 - (2 * radius);
    do {
      putpix(cx - xx, cy + yy);
      putpix(cx - yy, cy - xx);
      putpix(cx + xx, cy - yy);
      putpix(cx + yy, cy + xx);
      radius = err;
      if (radius <= yy) { err += ++yy * 2 + 1; }
      if ((radius > xx) || (err > yy)) { err += ++xx * 2 + 1; }
    } while (xx < 0);
  }

  // Bresenham's ellipse algorithm
  ellipse_bresenham (cx, cy, xradius, yradius, thickness = this.info.line.thickness) {

    // FIXME: thin ellipses not drawn correctly in C_WELL.RIP

    //this.log('bgi', `ellipse_bresenham: ${cx}, ${cy}, ${xradius}, ${yradius}, ${thickness}`); // DEBUG

    //console.log('ellipse_bresenham') // DEBUG
    if (xradius < 1) { xradius = 1; }
    if (yradius < 1) { yradius = 1; }

    // TEST: adjusting thick ellipse
    /*
    if (thickness === 3) {
      xradius -= 0.5;
      cx -= 0.5;
      yradius -= 0.5; // -0.5
      //cy += 0.5;
    }
    */

    const putpix = (thickness === 3)
      ? (a, b) => this.thick_putpixel(a, b)
      : (a, b) => this._putpixel(a, b);
    const xrad2 = 2 * xradius * xradius;
    const yrad2 = 2 * yradius * yradius;
    let x = -xradius, y = 0;
    let e2 = yradius, dx = (1+2*x)*e2*e2;
    //let e2 = yradius, dx = (2*x)*e2*e2; // TEST
    let dy = x*x, err = dx+dy;

    //this.log('bgi', 'ellipse_bresenham TEST2'); // DEBUG

    // draw x-axis pixels twice to correct XOR_PUT mode
    putpix(cx - x, cy);
    putpix(cx + x, cy);

    do {
      putpix(cx - x, cy + y);
      putpix(cx + x, cy + y);
      putpix(cx + x, cy - y);
      putpix(cx - x, cy - y);
      e2 = err << 1;
      if (e2 <= dy) { y++; dy += xrad2; err += dy; } // y step
      //if (e2 < dy) { y++; dy += xrad2; err += dy; } // y step TEST
      if (e2 >= dx || (err << 1) > dy) { x++; dx += yrad2; err += dx; } // x step
      //if (e2 >= dx || (2 * err) >= dy) { x++; dx += yrad2; err += dx; } // x step TEST
    } while (x < 0);

    // finish tip of ellipse (keep)
    while (y <= yradius) {
      putpix(cx, cy + y);
      putpix(cx, cy - y);
      y++;
    }
  }

  // FOR TESTING ONLY
  // does poorly on tall ellipses, and draws extra points.
  //
  ellipse_naive8 (cx, cy, xradius, yradius, thickness = this.info.line.thickness) {

    //this.log('bgi', `ellipse_naive8: ${cx}, ${cy}, ${xradius}, ${yradius}, ${thickness}`); // DEBUG

    const putpix = (thickness === 3)
      ? (a, b) => this.thick_putpixel(a, b)
      : (a, b) => this._putpixel(a, b);
    const xrad2 = xradius * xradius;
    const yasp = yradius / xradius;
    let x = 0, y = 0, xe = 0, ye = 0;

    do {
      y = Math.sqrt(xrad2 - (x * x));
      ye = y * yasp;
      xe = x * yasp;
      putpix(cx + x, cy + ye);
      putpix(cx + y, cy + xe);
      putpix(cx - x, cy + ye);
      putpix(cx - y, cy + xe);
      putpix(cx + x, cy - ye);
      putpix(cx + y, cy - xe);
      putpix(cx - x, cy - ye);
      putpix(cx - y, cy - xe);
      x += 1;
    } while (x < y)
  }

  // Bresenham's ellipse algorithm (alt implementation)
  // Trying to make this match RipTerm original, even if it's worse.
  //
  // Using this article to help develop this:
  // https://funloop.org/post/2021-03-15-bresenham-circle-drawing-algorithm.html
  //
  ellipse_bresenham2 (cx, cy, xradius, yradius, thickness = this.info.line.thickness) {

    // if not a circle, call the old function instead
    if (xradius !== yradius) {
      this.ellipse_bresenham(cx, cy, xradius, yradius, thickness);
      return;
    }

    //this.log('bgi', `ellipse_bresenham2: ${cx}, ${cy}, ${xradius}, ${yradius}, ${thickness}`); // DEBUG

    // only works for circles for now
    // (matches single thickness circles)

    // elipse equation is:
    // (x^2 / xrad^2) + (y^2 / yrad^2) = 1

    const putpix = (thickness === 3)
      ? (a, b) => this.thick_putpixel(a, b)
      : (a, b) => this._putpixel(a, b);
    const r = xradius;
    let x = 0, y = -yradius, fm = 1 - r, de = 3, dne = -(r << 1) + 5;
    do {
      putpix(cx + x, cy + y);
      putpix(cx + y, cy + x);
      putpix(cx - x, cy + y);
      putpix(cx - y, cy + x);
      putpix(cx + x, cy - y);
      putpix(cx + y, cy - x);
      putpix(cx - x, cy - y);
      putpix(cx - y, cy - x);

      if (fm < 0) {
        fm += de;
        dne += 2;
      }
      else {
        fm += dne;
        dne += 4;
        y++;
      }
      de += 2;
      x++;

    } while (x < -y);

  }

  // Draw an elliptical arc using Bresenham's algorithm.
  // stangle and endangle are in degrees counterclockwise, 0=right, 90=up...
  // NEW - TESTs show better than others
  // (may run slower than past attempts)

  arc_bresenham (cx, cy, stangle, endangle, xradius, yradius, thickness = this.info.line.thickness) {

    //this.log('bgi', `arc_bresenham: ${cx}, ${cy}, ${stangle}, ${endangle}, ${xradius}, ${yradius}, ${thickness}`); // DEBUG

    if (xradius < 1) { xradius = 1; }
    if (yradius < 1) { yradius = 1; }
    let asp_ratio = (yradius / xradius);
    const DEG_OVER_PI = 180.0 / Math.PI;
    //console.log('asp_ratio: ' + asp_ratio); // DEBUG

    // TODO: this is really close
    // this causes the overall arc drawing to slow down, wish we could speed it up
    let isInArc = (x, y) => {
      var angle = Math.floor(Math.atan2(cy - y, Math.round((x - cx) * asp_ratio)) * DEG_OVER_PI);
      if (angle < 0) { angle += 360 } // angle += 2 * Math.PI
      //console.log('angle:' + angle + ' start:' + stangle + ' end:' + endangle) // DEBUG
      return ((angle >= stangle) && (angle < endangle)) || (angle <= endangle - 360);
    }

    const putpix = (thickness === 3)
      ? (a, b) => this.thick_putpixel(a, b)
      : (a, b) => this._putpixel(a, b);

    const xrad2 = 2 * xradius * xradius;
    const yrad2 = 2 * yradius * yradius;
    let x = -xradius, y = 0;
    let e2 = yradius, dx = (1+2*x)*e2*e2;
    let dy = x*x, err = dx+dy;

    // draw x-axis pixels twice to correct XOR_PUT mode
    if (isInArc(cx - x, cy)) { putpix(cx - x, cy) }
    if (isInArc(cx + x, cy)) { putpix(cx + x, cy) }

    do {
/*
      let points = [
        [cx - x, cy + y],
        [cx + x, cy + y],
        [cx + x, cy - y],
        [cx - x, cy - y]
      ];
      for (let [px, py] of points) {
        if (isInArc(px, py)) { putpix(px, py) }
        //putpix(px, py)
      }
*/
      if (isInArc(cx - x, cy + y)) { putpix(cx - x, cy + y) }
      if (isInArc(cx + x, cy + y)) { putpix(cx + x, cy + y) }
      if (isInArc(cx + x, cy - y)) { putpix(cx + x, cy - y) }
      if (isInArc(cx - x, cy - y)) { putpix(cx - x, cy - y) }

      e2 = 2 * err;
      if (e2 <= dy) { y++; dy += xrad2; err += dy; } // y step
      if (e2 >= dx || (2 * err) > dy) { x++; dx += yrad2; err += dx; } // x step
    } while (x < 0);

    // finish tip of ellipse (keep)
    while (y <= yradius) {
      if (isInArc(cx, cy + y)) { putpix(cx, cy + y) }
      if (isInArc(cx, cy - y)) { putpix(cx, cy - y) }
      y++;
    }
  }

  // Bresenham's ellipse algorithm modified for filling
  fillellipse_bresenham (cx, cy, xradius, yradius) {

    //this.log('bgi', `fillellipse_bresenham: ${cx}, ${cy}, ${xradius}, ${yradius}`); // DEBUG

    const xrad2 = 2 * xradius * xradius;
    const yrad2 = 2 * yradius * yradius;
    let x = -xradius, y = 0;
    let e2 = yradius, dx = (1+2*x)*e2*e2;
    let dy = x*x, err = dx+dy;

    // center line fix for XOR_PUT mode
    for (let px = x; px <= -x; px++) {
      this.ff_putpixel(cx + px, cy);
    }

    let last_y = y;
    do {
      if (y != last_y) {
        // prevent repeat drawing same line for XOR_PUT mode.
        for (let px = x; px <= -x; px++) {
          this.ff_putpixel(cx + px, cy - y);
          this.ff_putpixel(cx + px, cy + y);
        }
        last_y = y;
      }
      e2 = 2 * err;
      if (e2 <= dy) { y++; dy += xrad2; err += dy; } // y step
      if (e2 >= dx || (2 * err) > dy) { x++; dx += yrad2; err += dx; } // x step
    } while (x <= 0);

    // finish tip of ellipse (likely not needed?)
    //while (y <= yradius) {
    //  this._putpixel(cx, cy + y);
    //  this._putpixel(cx, cy - y);
    //  y++;
    //}
  }

  // Draws an elliptical arc using slower method of trig and lines
  // CLOSEST so far for thickness == 3!!
  // thickness=3, off by 1 pixel at 0°,45°,135°??, ?, ?
  //
  // FIXME: doesn't work well with writeMode = BGI.XOR_PUT and thickness = 3
  // due to same pixels drawn multiple times.
  //
  arc_lines (cx, cy, stangle, endangle, xradius, yradius, thickness = this.info.line.thickness) {

    //this.log('bgi', `arc_lines: ${cx}, ${cy}, ${stangle}, ${endangle}, ${xradius}, ${yradius}, ${thickness}`); // DEBUG

    // following copied from ripscript.js v2 drawOvalArc()
    // TODO: find smoother algorithm

    const twoPiD = 2 * Math.PI / 360;
    if (stangle > endangle) { endangle += 360; }
    let x1 = cx + Math.floor(xradius * Math.cos(stangle * twoPiD));
    let y1 = cy - Math.floor(yradius * Math.sin(stangle * twoPiD));
    let x2, y2;

    // draw arc counter-clockwise
    for (let n = stangle; n <= endangle; n += 1) {
      // test with: Math.floor() .round() .trunc()
      x2 = cx + Math.floor(xradius * Math.cos(n * twoPiD));
      y2 = cy - Math.floor(yradius * Math.sin(n * twoPiD));
      this._line(x1, y1, x2, y2, this.info.fgcolor, BGI.COPY_PUT, BGI.SOLID_LINE, thickness);
      x1 = x2; y1 = y2;
    }
  }

  // TEST
  // draws an elliptical arc using slower method of trig and pixels
  arc_pixels (cx, cy, stangle, endangle, xradius, yradius, thickness = this.info.line.thickness) {

    // TODO: find smoother algorithm

    const putpix = (thickness === 3)
      ? (a, b) => this.thick_putpixel(a, b)
      : (a, b) => this._putpixel(a, b);
    const twoPiD = 2 * Math.PI / 360;
    if (stangle > endangle) { endangle += 360; }
    let x, y;
    // calculate a single pixel step along the circumference
    let step = 1 / (twoPiD * Math.max(xradius, yradius));

    // draw arc counter-clockwise
    for (let n = stangle; n <= endangle; n += step) {
      // test with: Math.floor() .round() .trunc()
      x = cx + Math.round(xradius * Math.cos(n * twoPiD));
      y = cy - Math.round(yradius * Math.sin(n * twoPiD));
      putpix(x, y);
    }
  }

  // Bresenham's line algorithm
  line_bresenham (x1, y1, x2, y2, color, wmode, upattern = 0xFFFF) {

    const
      dx = Math.abs(x2 - x1),
      //dy = Math.abs(y2 - y1) >> 1 << 1; // force even TEST #5 (didn't fix)
      dy = Math.abs(y2 - y1);
    let
      xi1, xi2, yi1, yi2, den, num, numadd,
      x = x1, y = y1, numpixels;

    if (x2 >= x1) { xi1 = xi2 = 1; } else { xi1 = xi2 = -1; }
    if (y2 >= y1) { yi1 = yi2 = 1; } else { yi1 = yi2 = -1; }
    if (dx >= dy) {
      xi1 = yi2 = 0;
      den = dx;
      num = dx >> 1;
      //den = dx - 1;   // TEST #2 didn't fix
      //den = num << 1; // TEST #4 not that close
      numadd = dy;
      numpixels = dx;
    }
    else {
      xi2 = yi1 = 0;
      den = dy;
      num = dy >> 1;

      // TESTING (TO REMOVE)
      //den = dy - 1; // TEST #2 partial
      //den = dy + 1; // TEST #6
      //num = dy; // TEST #5
      //den = num << 1; // TEST #4 on to something!? NOT same as TEST #2
      // if dy=205, dy-1 = 204, and num<<1 = 204
      // no difference, so why does it render differently??
      // is it because den is forced to be an integer when using << ?
      // AH, renders same for THAT case, but not in cases when dy is even!
      //num = Math.round(dy / 2); // didn't fix
      //num = dy / 2;  // try floats (no change!?)
      //numadd = dx + 1;  // closer on lines, but not on rest TEST #6
      //numadd = dx - 1;  // didn't fix
      //numpixels = dy + 1;  // TEST #1

      numadd = dx;
      numpixels = dy;
    }

    // TESTING (TO REMOVE)
    //if ((dx % 2 === 1) && (dx > 6)) {
    //if (x1 === 220) {
    //  console.log({ x1, y1, x2, y2, dx, dy, den, num, numadd }); // DEBUG
    //}

    for (let c=0; c <= numpixels; c++) {
      if ((upattern >> (c % 16)) & 1) {
        this._putpixel(x, y, color, wmode);
      }
      num += numadd;
      if (num >= den) {
      //if (num > den) {    // TEST didn't fix
        //num -= (den - 1); // TEST #3 partial
        num -= den;
        x += xi1;
        y += yi1;
      }
      x += xi2;
      y += yi2;
    }

  }

  // Returns the octant (1-8) where (x, y) lies, used by line().
  // TODO: needs further testing on edge cases ('>=' vs '>')
  octant (x, y) {
    // x=40, y=-40
    // GARFIELD.RIP - OK
    //return (x >= 0) ? ( (y >= 0) ? (( x > y) ? 1 : 2) : (( x > -y) ? 8 : 7) )
    //                : ( (y >= 0) ? ((-x > y) ? 4 : 3) : ((-x > -y) ? 5 : 6) );

    // Fixes L_LINE.RIP, but breaks GARFIELD.RIP floodfill!
    //return (x >= 0) ? ( (y >= 0) ? (( x > y) ? 1 : 2) : (( x >= -y) ? 8 : 7) )
    //                : ( (y >= 0) ? ((-x > y) ? 4 : 3) : ((-x > -y) ? 5 : 6) );

    // this solves L_LINE2.RIP but still breaks GARFIELD.RIP
    // ** BEST GUESS SO FAR **
    return (x >= 0) ? ( (y > 0) ? (( x >= y) ? 1 : 2) : (( x >= -y) ? 8 : 7) )
                    : ( (y >= 0) ? ((-x > y) ? 4 : 3) : ((-x >= -y) ? 5 : 6) );

    // this fixes GARFIELD.RIP (see slanted black line at right edge of canvas)
    // compare to screenshot, it looks like floodfill() is responsible for not leaking at that point.
    // so not fixable in this function!
    //return (x >= 0) ? ( (y > 0) ? (( x >= y) ? 1 : 2) : (( x > -y) ? 8 : 7) )
    //                : ( (y >= 0) ? ((-x > y) ? 4 : 3) : ((-x >= -y) ? 5 : 6) );
  }

  ////////////////////////////////////////////////////////////////////////////////
  // Font methods

  // download and parse given .CHR font file.
  // filename = filename without path, including '.CHR' (e.g. 'BOLD.CHR')
  // uses this.fontsPath
  // returns font object or undefined.
  //
  async fetchCHRFont (filename) {

    if (typeof filename !== 'string') {
      this.log('err', 'fetchCHRFont() missing "filename"!');
      return;
    }
    if (typeof this.fontsPath !== 'string') {
      this.log('err', 'must pass in "fontsPath" to BGI()!');
      return;
    }
    const url = this.fontsPath + '/' + filename;
    this.log('fnt', 'Fetching CHR font: ' + url);
    let request = new Request(url);
    try {
      const response = await fetch(request);
      if (!response.ok) throw new Error("Request failed");
      const buffer = await response.arrayBuffer();

        const dview = new DataView(buffer);
        if (dview.getUint8(0x80) === 43) { // 43 = '+'

          // Save font metadata
          let font = {};
          font.numchars = dview.getUint16(0x81, true); // true = little-endian
          font.firstchar = dview.getUint8(0x84); // ASCII value of first char
          font.top = dview.getInt8(0x88); // top of capital, going up from baseline
          font.baseline = dview.getInt8(0x89); // usually 0?
          font.bottom = dview.getInt8(0x8A); // bottom descender, usually negative

          // Create a copy of the char widths byte array buffer to store into font object.
          const widthOffset = 0x90 + (font.numchars * 2);
          const fontWidths = new Uint8Array(buffer, widthOffset, font.numchars);
          font.widths = fontWidths.slice(0); // widths are indexed 0 to numchars-1

          //console.log(font); // DEBUG

          // Must read offsets to char defs stored in file, as some reuse the same def. (e.g. SANS.CHR)
          const charOffsets = new Uint16Array(buffer, 0x90, font.numchars);
          const dataOffset = dview.getUint16(0x85, true) + 0x80;
          const defdata = new Uint8Array(buffer, dataOffset); // length to EOF
          font.data = []; // array of Uint8Array slices, indexed 0 to numchars-1

          // Each pair of bytes is an (x, y) coord, and 2-bit opcode encoded in each high bit.
          // All we care about here is to slice whenever both high bits are 0,
          // which means End of Character definition.
          if ((defdata.length % 2) !== 0) {
            this.log('err', 'Font data length should be a multiple of 2, so something is wrong.');
            return;
          }

          // Scan definition data starting at each offset in charOffsets[] to create sliced copies.
          let byte1, byte2, pos = 0, spos = 0;
          for (let i=0; i < font.numchars; i++) {
            spos = charOffsets[i];
            pos = spos;
            do {
              byte1 = defdata[pos];
              byte2 = defdata[pos + 1];
              pos += 2;
            } while (!(((byte1 & 0x80) === 0) && ((byte2 & 0x80) === 0)) && (pos < defdata.length - 1))
            font.data.push( defdata.slice(spos, pos) );
          }

          return font;
        }
        else {
          this.log('fnt', 'CHR file marker not correct');
          return;
        }
    }
    catch (e) {
      this.log('err', `fetchCHRFont error: ${e}`);
      return;
    }
  } // end fetchCHRFont()

  // uses and updates this.info.cp, and this.fonts
  // value = ASCII code value of a single character as int.
  // fontname = filename including .CHR (e.g. 'GOTH.CHR') MAY CHANGE.
  // scale = 1 to 10, indexes into BGI.fontScales[] for actual scale factor.
  drawChar (value, fontname, scale, dir) {

    // current postion (cp) should only update when dir is horizontal, not vertical! (see specs v2.0)

    // check for valid input
    if ((fontname in this.fonts) === false) {
      this.log('fnt', `${fontname} font not found!`);
      return;
    }

    const font = this.fonts[fontname];
    const color = this.info.fgcolor;
    const actualScale = (scale < BGI.fontScales.length) ? BGI.fontScales[scale] : 1;

    if ((value < font.firstchar) || (value >= font.firstchar + font.numchars)) {
      this.log('fnt', `value ${value} out of range in ${fontname}`);
      return;
    }

    // scan char data
    const chardata = font.data[value - font.firstchar];
    let xbyte, ybyte, dx, dy, x, y;
    let numcmds = chardata.length;
    const x0 = this.info.cp.x;
    const y0 = this.info.cp.y;

    for (let i=0; i < numcmds; i+=2) {

      // read next byte pair
      xbyte = chardata[i];
      ybyte = chardata[i + 1];

      // convert the one's-complement 7-bit signed values
      dx = (xbyte & 0x40) ? -(-xbyte & 0x3F) : (xbyte & 0x3F);
      dy = (ybyte & 0x40) ? -(-ybyte & 0x3F) : (ybyte & 0x3F);

      // multiply by scale factor
      dx = Math.trunc(dx * actualScale);
      dy = Math.trunc(dy * actualScale);

      // handle direction
      x = x0 + ((dir === BGI.HORIZ_DIR) ?  dx : -dy);
      y = y0 + ((dir === BGI.HORIZ_DIR) ? -dy : -dx);

      if (((xbyte & 0x80) !== 0) && (ybyte & 0x80) === 0) {
        // move pointer when high bits 1 & 0
        //console.log('move', xbyte, ybyte, dx, dy); // DEBUG
        this.moveto(x, y);
      }
      else if (((xbyte & 0x80) !== 0) && (ybyte & 0x80) !== 0) {
        // draw line when high bits 1 & 1
        //console.log('draw', xbyte, ybyte, dx, dy); // DEBUG
        this.lineto(x, y, color, BGI.COPY_PUT, BGI.SOLID_LINE, BGI.NORM_WIDTH);
      }
      else {
        //console.log('????', xbyte, ybyte, dx, dy); // DEBUG
      }
    }

  }

  // Downloads a PNG file containing a bitmap font.
  // xsize & ysize is the size in pixels of an individual character. (e.g. 8x8)
  // PNG file must arrange all ASCII chars in a 16 x 16 grid.
  // PNG size must be at least (16 * xsize) by (16 * ysize) pixels.
  // OFF pixels are black (0,0,0), else they are ON pixels.
  // filename = filename without path, including '.png' (e.g. '8x8.png')
  // will use this.fontsPath + '/' + filename for full path.
  // Returns an array of 256 elements for each ASCII char,
  //   each element is an array of ints, each being a scanline of height values.
  //   e.g. [65][2] is ASCII char 65, 3rd scanline which returns an 8-bit int.
  //
  async fetchPNGFont (filename, xsize, ysize) {

    // checking args
    if (typeof filename !== 'string') {
      this.log('err', 'fetchPNGFont() missing "filename"!');
      return;
    }
    if (typeof this.fontsPath !== 'string') {
      this.log('err', 'must pass in "fontsPath" to BGI()!');
      return;
    }
    const url = this.fontsPath + '/' + filename;
    this.log('fnt', 'Fetching PNG font: ' + url);

    let img = new Image();
    return new Promise((resolve, reject) => {
      img.onload = () => {
        const imgW = img.naturalWidth;
        const imgH = img.naturalHeight;

        // PNG size must be at least (16 * xsize) by (16 * ysize) pixels.
        const minW = xsize * 16, minH = ysize * 16;
        if ((imgW < minW) || (imgH < minH)) {
          //this.log('err', `PNG size is ${imgW}x${imgH} but needs to be at least ${minW}x${minH}!`);
          reject(new Error(`PNG size is ${imgW}x${imgH} but needs to be at least ${minW}x${minH}!`));
        }

        // preparing font image
        const can1 = document.createElement('canvas');
        can1.setAttribute('width', imgW);
        can1.setAttribute('height', imgH);
        const ctx1 = can1.getContext('2d');
        ctx1.drawImage(img, 0, 0);
        const imgdata1 = ctx1.getImageData(0, 0, imgW, imgH);
        // imgdata1.data is Uint8ClampedArray(w * h * 4) // RGBA

        // for every ASCII char
        let abitfont = [];
        let pos = 0, bit = 0;
        for (let i=0; i < 256; i++) {
          // upper-left pixel of char in PNG
          let x0 = (i % 16) * xsize;
          let y0 = Math.floor(i / 16) * ysize;
          let bitchar = []; // array of scanline values (1 for each row)

          for (let y=0; y < ysize; y++) {
            let scanline = 0;
            // looping x in reverse so bits are shifted on scanline in reverse order
            for (let x = xsize - 1; x >= 0; x--) {
              pos = ((y0 + y) * imgW + x0 + x) * 4;
              bit = (imgdata1.data[pos] != 0) ? 1 : 0; // just checking red value
              scanline = (scanline << 1) | bit;
            }
            bitchar.push(scanline);
          }
          abitfont.push(bitchar);
        }
        resolve(abitfont);
      }; // end onload

      img.onerror = (error) => {
        reject(new Error("Failed to load PNG font"));
      };

      img.src = url;
    }); // end Promise
  }

  // Uses and updates this.info.cp, this.bitfonts, info.fgcolor
  // value: ASCII code value of a single character as int.
  // fontnum: size value 0-4
  // scale: 1 to 10 ?
  // dir: 0 = horizontal, 1 = vertical
  // TODO: should pass in info.fgcolor ?
  // TODO: pass in flag to prevent overriding this.info.cp ?
  //
  drawPNGChar (value, fontnum, scale, dir, x0 = this.info.cp.x, y0 = this.info.cp.y) {

    if (value < 0 || value > 255) { return; }
    if (scale < 1) { scale = 1; }
    let bitchar = this.bitfonts[fontnum][value]; // FIXME: may be undefined!
    if (bitchar === undefined) { return; }

    const ysize = bitchar.length;
    const xsize = 8; // TODO: need to store sizes in lookup table!

    // loop thru each scanline
    let scanline, bit, x1, y1;
    for (let y=0; y < ysize; y++) {
      scanline = bitchar[y];
      for (let x=0; x < xsize; x++) {
        bit = scanline & 1;
        scanline = scanline >> 1;
        if (bit) {
          if (scale > 1) {
            // square pixel
            if (dir === BGI.HORIZ_DIR) {
              x1 = x0 + (x * scale);
              y1 = y0 + (y * scale);
            }
            else {
              x1 = x0 + (y * scale);
              y1 = y0 - (x * scale);
            }
            this._bar(x1, y1, x1 + scale - 1, y1 + scale - 1,
              this.info.fgcolor, this.info.writeMode, BGI.SOLID_FILL);
          }
          else {
            // single pixel
            if (dir === BGI.HORIZ_DIR) {
              this._putpixel(x0 + x, y0 + y); // TODO: try _putpixel_abs() later?
            }
            else {
              this._putpixel(x0 + y, y0 - x);
            }
          }
        }
      }
    }
    // increment current position
    if (dir === BGI.HORIZ_DIR) {
      this.info.cp.x += (xsize * scale);
    }
    else {
      this.info.cp.y -= (xsize * scale);
    }
  }

  ////////////////////////////////////////////////////////////////////////////////
  // Image methods

  // Download and parse given .ICN or .HIC icon image file.
  // Assumes 16-color images, 4-bits per pixel.
  // filename = filename without path.
  // uses this.iconsPath
  // returns: image object or undefined.
  // { width:int, height:int, data:Uint8ClampedArray, url:string }
  //
  async fetchIcon (url) {

    if (typeof url !== 'string') {
      this.log('err', 'fetchIcon() missing "url"!');
      return;
    }
    this.log('bgi', `Fetching icon: ${url}`);

    let request = new Request(url);
    try {
      const response = await fetch(request);
      if (!response.ok) throw new Error("Request failed");
      const buffer = await response.arrayBuffer();

        // no magic number in ICN files to test against
        const dview = new DataView(buffer);
        const imgWidth = dview.getUint16(0, true) + 1; // true = little-endian
        const imgHeight = dview.getUint16(2, true) + 1;

        // reject 1x1 images and those that can't fit in canvas
        if ((imgWidth > 1) && (imgWidth <= this.width) && (imgHeight > 1) && (imgHeight <= this.height)) {

          // decoding 4 bit-planes (16 colors)
          let data = new Uint8ClampedArray(imgWidth * imgHeight + 8); // 8 extra slack pixels
          const fileData = new Uint8Array(buffer, 4); // byte 4 to EOF

          // calculate num bytes per bit-plane scan line, rounded up to 8-bit multiple
          const byteWidth = Math.ceil(imgWidth / 8);

          // read 4 bit-planes * byteWidth bytes each, per scanline (y)
          let x, i=0, o=0, bp0, bp1, bp2, bp3, pix;
          const off2 = byteWidth, off1 = byteWidth * 2, off0 = byteWidth * 3;
          for (let y=0; y < imgHeight; y++) {
            x = 0;
            i = y * byteWidth * 4; // input index
            o = y * imgWidth; // output index
            for (let b=0; b < byteWidth; b++) {
              bp3 = fileData[i + b];
              bp2 = fileData[i + b + off2];
              bp1 = fileData[i + b + off1];
              bp0 = fileData[i + b + off0];
              // convert bits from these 4 bit-plane bytes into 8 byte pixels (16-colors)
              for (let p=7; p >= 0; p--) {
                pix = ((bp3 & 1) << 3) | ((bp2 & 1) << 2) | ((bp1 & 1) << 1) | (bp0 & 1);
                bp3 = bp3 >> 1;
                bp2 = bp2 >> 1;
                bp1 = bp1 >> 1;
                bp0 = bp0 >> 1;
                data[o+p] = pix;
              }
              o += 8;
            }
          }
          return { width: imgWidth, height: imgHeight, data: data, url: url };
        }
        else {
          this.log('bgi', `invalid icon? (${imgWidth} x ${imgHeight})`);
          return;
        }
    }
    catch (e) {
      this.log('err', `fetchIcon error: ${e}`);
      return;
    }
  }

  // Converts image.data to a base64 encoded href string.
  // Uses current palette and data to create an embedded PNG.
  // Doesn't handle writeMode, stores as BGI.COPY_PUT.
  // Returns "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
  //
  imageToDataURL (image, defaults = {}) {
    const {
      pal = this.palette
    } = defaults;

    if (!(image && image.width && image.height && image.data)) {
      this.log('err', 'imageToDataURL() missing "image"!');
      return;
    }

    // can use image.data byte buffer, don't need a new one
    const w = image.width, h = image.height;

    // create a temp canvas to write image to
    const canvas = document.createElement("canvas");
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext("2d");
    const imgData = ctx.createImageData(w, h);

    // copy 8-bit image.data to 32-bit canvas using current global palette
    this.refresh({ ctx:ctx, img:imgData, pix:image.data, pal:pal, isBuffered:true });

    // output canvas as a PNG data URL
    return canvas.toDataURL("image/png");
  }


  ////////////////////////////////////////////////////////////////////////////////
  // Standard BGI functions
  // SEE https://www.cs.colorado.edu/~main/bgi/doc/
  // To convert Pascal syntax to JS, simply convert function names to lowercase.

  // Draw a circular arc centered at (x, y), from stangle to endangle in degrees,
  // counterclockwise with 0 = 3 o'clock, 90 = 12 o'clock, etc.
  // doesn't use linestyle.
  arc (cx, cy, stangle, endangle, radius, thickness = this.info.line.thickness) {
    this._arc(cx, cy, stangle, endangle, radius, thickness);
  }
  _arc (cx, cy, stangle, endangle, radius, thickness = this.info.line.thickness) {
    // adjust radius based on aspect ratio
    // TODO: this may be off?
    const yradius = Math.floor( radius * (this.aspect.xasp / this.aspect.yasp) );
    this._ellipse(cx, cy, stangle, endangle, radius, yradius, thickness);
  }

  // Draws and fills a rectangle, using fill style and color. Has no border. (see fillrect)
  // For a border, use bar3d with depth = 0.
  bar (left, top, right, bottom, color = this.info.fill.color, wmode = this.info.writeMode,
        fillstyle = this.info.fill.style ) {
    this._bar(left, top, right, bottom, color, wmode, fillstyle);
  }
  _bar (left, top, right, bottom, color = this.info.fill.color, wmode = this.info.writeMode,
        fillstyle = this.info.fill.style ) {

    // swap
    if (left > right) { let tmp = left; left = right; right = tmp; }
    if (top > bottom) { let tmp = top; top = bottom; bottom = tmp; }

    const fillcolor = (fillstyle === BGI.EMPTY_FILL) ? this.info.bgcolor : color;

    if (fillstyle === BGI.SOLID_FILL) {
      // draw filled solid bar
      for (let y = top; y <= bottom; y++) {
        for (let x = left; x <= right; x++) {
          this._putpixel(x, y, fillcolor, wmode);
        }
      }
    }
    else {
      // draw filled patterned bar
      for (let y = top; y <= bottom; y++) {
        for (let x = left; x <= right; x++) {
          this.ff_putpixel(x, y, fillcolor, wmode);
        }
      }
    }
  }

  // Draws a 3D filled rectangle using fill color and pattern.
  // Outline drawn using line style and fg color.
  // if topflag != 0, draws a top.
  bar3d (left, top, right, bottom, depth, topflag) {

    /* topflag = true           false
           +----------+                  +
          /          /|                 /|
         +----------+ |     +----------+ |
         |XXXXXXXXXX| +     |XXXXXXXXXX| +
         |XXXXXXXXXX|/      |XXXXXXXXXX|/
         +----------+       +----------+

      Outline uses info.fgcolor & info.line...
      Fill uses info.fill.color & fill.style, or info.bgcolor if using EMPTY_FILL.
      All use info.writeMode. Sides aren't filled in.
    */

    // swap
    if (left > right) { let tmp = left; left = right; right = tmp; }
    if (top > bottom) { let tmp = top; top = bottom; bottom = tmp; }

    // draw filled bar
    const fillcolor = (this.info.fill.style === BGI.EMPTY_FILL) ? this.info.bgcolor : this.info.fill.color;
    this.bar(left, top, right, bottom, fillcolor)

    // following drawn using info.fgcolor
    if (depth > 0) {
      // TODO: doesn't seem to fill in 3d part, only draws an outline?
      // also calling line() would have thickness and line pattern
      if (topflag) {
        this._line(left, top, left + depth, top - depth);
        this._line(left + depth, top - depth, right + depth, top - depth);
      }
      this._line(right, top, right + depth, top - depth);
      this._line(right, bottom, right + depth, bottom - depth);
      this._line(right + depth, bottom - depth, right + depth, top - depth);
    }
    this.rectangle(left, top, right, bottom);
  }

  // doesn't use linestyle
  circle (cx, cy, radius, thickness = this.info.line.thickness) {
    this._arc(cx, cy, 0, 360, radius);
  }
  _circle (cx, cy, radius, thickness = this.info.line.thickness) {
    this._arc(cx, cy, 0, 360, radius);
  }

  // Clears the screen, filling it with the current background color.
  // Resets CP to (0,0).
  cleardevice (bgcolor = this.info.bgcolor) {

    this.info.cp.x = 0;
    this.info.cp.y = 0;
    this.pixels.fill(bgcolor);
    this.fillpixels.fill(0);
    this.icons = {}; // TEST: do we want to do this here? (clears image ids)
  }

  // Clears the viewport, filling it with the current background color.
  // Resets cp to (0,0).
  clearviewport (bgcolor = this.info.bgcolor) {

    this.info.cp.x = 0;
    this.info.cp.y = 0;
    const vp = this.info.vp;
    for (let y = vp.top; y <= vp.bottom; y++) {
      const row = y * this.width;
      for (let x = vp.left; x <= vp.right; x++) {
        this.pixels[row + x] = bgcolor;
      }
      /*
      // maybe or maybe not faster
      const st = (y * this.width) + vp.left;
      const en = (y * this.width) + vp.right + 1;
      this.pixels.fill(bgcolor, st, en)
      */
    }
  }

  // not implemented (STUB)
  detectgraph (graphdriver, graphmode) {
    // int *graphdriver, int *graphmode
    this.unimplemented('detectgraph')
  }

  // Draws a Cubic Bezier. [NOT IN BGI - RIPscrip extension]
  // numsegments is number of segments in curve.
  // cntpoints is an array of 8 ints (4 control points):
  //  [x1, y1, x2, y2, x3, y3, x4, y4]
  // (x1, y1) and (x4, y4) are on the curve, while others are not.
  // uses line style, thickness, and write mode.
  drawbezier (numsegments, cntpoints) {
    this._drawbezier(numsegments, cntpoints);
  }

  // Calculate adaptive segment count based on control polygon length.
  // Algorithm reverse-engineered from RSKETCH.EXE (Wayne Thomas, 1993) via Ghidra.
  // Found at function FUN_1000_62b9 in the binary.
  // Formula: segments = (|P1-P0| + |P2-P1| + |P3-P2|) / 10, clamped to [5, 500]
  _calculateBezierSegments (cntpoints) {
    const [x1, y1, x2, y2, x3, y3, x4, y4] = cntpoints;
    const d01 = Math.sqrt((x2 - x1) ** 2 + (y2 - y1) ** 2);
    const d12 = Math.sqrt((x3 - x2) ** 2 + (y3 - y2) ** 2);
    const d23 = Math.sqrt((x4 - x3) ** 2 + (y4 - y3) ** 2);
    const segments = Math.floor((d01 + d12 + d23) / 10);
    return Math.max(5, Math.min(500, segments));
  }

  _drawbezier (numsegments, cntpoints) {
    // Cubic Bezier formula (Bernstein polynomial):
    // p = (1-t)^3 *P0 + 3*t*(1-t)^2*P1 + 3*t^2*(1-t)*P2 + t^3*P3
    // where t is 0 to 1

    if (!(cntpoints && (cntpoints.length >= 8))) {
      this.log('bgi', `drawbezier() invalid args! numsegments: ${numsegments}, cntpoints: ${cntpoints}`);
      return;
    }

    // Use adaptive subdivision if numsegments is 0, null, or invalid
    // This matches the algorithm from period-correct DOS implementations
    if (!numsegments || numsegments < 1) {
      numsegments = this._calculateBezierSegments(cntpoints);
    }

    const [x1, y1, x2, y2, x3, y3, x4, y4] = cntpoints;
    let step = 1 / numsegments;
    let xp = x1, yp = y1, xn, yn;
    for (let t = step; t < 1; t += step) {
      let t1 = 1 - t;
      xn = Math.floor( t1*t1*t1 * x1 + 3 * t * t1*t1 * x2 + 3 * t*t * t1 * x3 + t*t*t * x4 );
      yn = Math.floor( t1*t1*t1 * y1 + 3 * t * t1*t1 * y2 + 3 * t*t * t1 * y3 + t*t*t * y4 );
      this._line(xp, yp, xn, yn);
      xp = xn;
      yp = yn;
    }
    this._line(xp, yp, x4, y4);
  }

  // Draws a closed polygon.
  // polypoints is an array of ints: [x1, y1, x2, y2, ... xn, yn]
  // where n = numpoints.
  drawpoly (numpoints, polypoints, color = this.info.fgcolor) {
    this._drawpoly(numpoints, polypoints, color);
  }
  _drawpoly (numpoints, polypoints, color = this.info.fgcolor) {
    // polypoints array of ints

    if (!(numpoints && polypoints && (numpoints >= 2) && (polypoints.length >= numpoints * 2))) {
      this.log('bgi', `drawpoly() invalid args! numpoints: ${numpoints}, polypoints: ${polypoints}`);
      return;
    }

    for (let n=0; n < numpoints - 1; n++) {
      this._line(polypoints[2*n], polypoints[2*n + 1],  polypoints[2*n + 2], polypoints[2*n + 3], color);
    }
    // close the polygon
    this._line(polypoints[2 * numpoints - 2], polypoints[2 * numpoints - 1], polypoints[0], polypoints[1], color);
  }

  // Draws a polyline. [NOT IN BGI]
  // polypoints is an array of ints: [x1, y1, x2, y2, ... xn, yn]
  // where n = numpoints.
  drawpolyline (numpoints, polypoints, color = this.info.fgcolor) {
    this._drawpolyline(numpoints, polypoints, color);
  }
  _drawpolyline (numpoints, polypoints, color = this.info.fgcolor) {
    // polypoints array of ints

    if (!(numpoints && polypoints && (numpoints >= 2) && (polypoints.length >= numpoints * 2))) {
      this.log('bgi', `drawpolyline() invalid args! numpoints: ${numpoints}, polypoints: ${polypoints}`);
      return;
    }

    for (let n=0; n < numpoints - 1; n++) {
      this._line(polypoints[2*n], polypoints[2*n + 1],  polypoints[2*n + 2], polypoints[2*n + 3], color);
    }
  }

  // Draw an elliptical arc centered at (x, y), from stangle to endangle in degrees,
  // counterclockwise with 0 = 3 o'clock, 90 = 12 o'clock, etc.
  // doesn't use linestyle.
  ellipse (cx, cy, stangle, endangle, xradius, yradius, thickness = this.info.line.thickness) {
    this._ellipse(cx, cy, stangle, endangle, xradius, yradius, thickness);
  }
  _ellipse (cx, cy, stangle, endangle, xradius, yradius, thickness = this.info.line.thickness) {

    // need these
    if (stangle === endangle) { return }
    if ((xradius === 0) && (yradius === 0) && (thickness > 1)) { return }
    if (xradius < 1) { xradius = 1; }
    if (yradius < 1) { yradius = 1; }

    // fix for some arcs
    if (stangle > endangle) { endangle += 360 }

    // Angle span determines rendering path.
    // RIPTERM.EXE (FUN_368f_2c06) uses three thresholds:
    //   span > 349° → full ellipse (handles near-complete arcs)
    //   span < 2°   → line approximation
    //   else        → arc segments
    const angleSpan = endangle - stangle;

    if (angleSpan > 349) {
      // Full ellipse path — RIPTERM treats spans > 349° as complete ellipses
      // to avoid visible gaps from floating-point endpoint mismatch.
      if (thickness === 1) {
        this.ellipse_bresenham(cx, cy, xradius, yradius, thickness);
      }
      else {
        this.arc_lines(cx, cy, 0, 360, xradius, yradius, thickness);
      }
    }
    else if (angleSpan < 2) {
      // Very small arc — RIPTERM approximates as a line between endpoints.
      const rad_st = stangle * Math.PI / 180;
      const rad_en = endangle * Math.PI / 180;
      const x0 = Math.round(cx + xradius * Math.cos(rad_st));
      const y0 = Math.round(cy - yradius * Math.sin(rad_st));
      const x1 = Math.round(cx + xradius * Math.cos(rad_en));
      const y1 = Math.round(cy - yradius * Math.sin(rad_en));
      this.line(x0, y0, x1, y1);
    }
    else {
      // Partial arc
      if (thickness === 1) {
        this.arc_bresenham(cx, cy, stangle, endangle, xradius, yradius, thickness);
      }
      else {
        this.arc_lines(cx, cy, stangle, endangle, xradius, yradius, thickness);
      }
    }
  }

  fillellipse (cx, cy, xradius, yradius) {
    this._fillellipse(cx, cy, xradius, yradius);
  }
  _fillellipse (cx, cy, xradius, yradius) {

    // TODO: don't know if these are correct, or should we exit?
    if (xradius < 1) { xradius = 1; }
    if (yradius < 1) { yradius = 1; }

    // TODO: should this be outlined here or in ripterm code?
    this.fillellipse_bresenham(cx, cy, xradius, yradius)
  }

  // Draw a filled polygon, using current fill pattern, fill color and bgcolor.
  // pp is an array of ints: [x1, y1, x2, y2, ... xn, yn]
  // where n = numpoints.
  fillpoly (numpoints, pp) {
    this._fillpoly(numpoints, pp);
  }
  //_fillpoly (numpoints, pp) { // TODO: uncomment after done testing
  _fillpoly (numpoints, pp, debugFlag = false) { // temp
    // polypoints array of ints

    // code based on: http://alienryderflex.com/polygon_fill/
    const vp = this.info.vp;
    let i, j, x, y, xnode, xval;

    // scan thru all rows in viewport
    for (y = vp.top; y <= vp.bottom; y++) {
      xnode = [];

      // build node list
      j = numpoints - 1;
      for (i=0; i < numpoints; i++) {
        const tx1 = pp[j*2], ty1 = pp[j*2+1];
        const tx2 = pp[i*2], ty2 = pp[i*2+1];
        //const tx1 = pp[j*2] + 1, ty1 = pp[j*2+1]; // TEST
        //const tx2 = pp[i*2] + 1, ty2 = pp[i*2+1]; // TEST

        //if ( ((pp[i*2+1] < y) && (pp[j*2+1] >= y)) || ((pp[j*2+1] < y) && (pp[i*2+1] >= y)) ) {
        //if ( ((ty2 < y) && (ty1 >= y)) || ((ty1 < y) && (ty2 >= y)) ) { // orig
        if ( ((ty2 <= y) && (ty1 > y)) || ((ty1 <= y) && (ty2 > y)) ) { // better for SKATE.RIP
        //if ( ((ty2 <= y) && (ty1 > y)) || ((ty1 <= y) && (ty2 > y)) || ((ty1 === y) && (ty2 === y)) ) {
        //if ( ((ty2 <= y) && (ty1 >= y)) || ((ty1 <= y) && (ty2 >= y)) ) {

          // FIXME: there are off-by-one pixels along edges of polygons.
          // I tried lots of combinations, but could not solve it...

          // Note for GHOST.RIP: off pixels are always on left-hand side
          // while polyline wraps around clockwise. Off-pixel increases left side +1.

          //xval = (y - pp[i*2+1]) / (pp[j*2+1] - pp[i*2+1]) * (pp[j*2] - pp[i*2]) + pp[i*2];
          //xval = (y - ty2) / (ty1 - ty2) * (tx1 - tx2) + tx2; // orig

          // fix for divide by 0 if ty1 - ty2 == 0
          xval = (ty1 === ty2) ? tx2 : (y - ty2) / (ty1 - ty2) * (tx1 - tx2) + tx2;

          // TEST #1: still not solving it (TO REMOVE)
          //xval = (ty1 === ty2) ? tx2 : (y - ty2) / (ty1 - ty2) * (tx1 - tx2 + 1) + tx2;
          //xval = (ty1 === ty2) ? tx2 : Math.trunc((y - ty2) / (ty1 - ty2)) * (tx1 - tx2) + tx2; // REMOVE
          //xval = (ty1 === ty2) ? tx2 : (y - ty2) * (tx1 - tx2) / (ty1 - ty2) + tx2; // TEST (same)

          //TO REMOVE
          // tried this, didn't solve bug
          /*
          const oct = this.octant(tx2 - tx1, ty1 - ty2);
          if ((oct === 1) || (oct === 4) || (oct ===5) || (oct === 8)) {
            xnode.push( Math.ceil(xval) );
          }
          else {
            xnode.push( Math.floor(xval) );
          }
          */

          // tried all these (REMOVE)
          //xnode.push( Math.ceil(xval) );
          //xnode.push( Math.floor(xval) ); // orig
          //xnode.push( Math.trunc(xval) );
          //xnode.push( xval | 0 ); // same as trunc() but slightly faster?
          //xnode.push( Math.round(xval) | 0 );

          // TEST #2
          // this solves trees in SKATE.RIP
          // doesn't solve GHOST.RIP, OUT-AD.RIP, or others.
          //xnode.push( ((ty1 - ty2) > 0) ? Math.ceil(xval) : Math.floor(xval) );

          // TEST #3: store floats (getting closer)
          xnode.push(xval);
        }
        j = i;
      }

      // sort nodes
      if (xnode.length == 0) continue;
      xnode.sort(function(a, b) { return a - b; });

      /*
      // DEBUG: must set y to value to test
      const TEST_Y = 200;
      if (debugFlag && (y === TEST_Y)) {
        this.log('bgi', `fillpoly: y=${y}, xnode=${xnode}`); // DEBUG
      }
      */

      // draw pixels between node pairs
      for (i=0; i < xnode.length; i+=2) {

        //const x0 = xnode[i], x1 = xnode[i+1]; // orig
        const x0 = Math.ceil(xnode[i]), x1 = Math.floor(xnode[i+1]); // TEST #3 (getting closer)

        for (x = x0; x <= x1; x++) {
          this.ff_putpixel(x, y);
        }
      }
    }
    // this fixes filled polygon border issues
    // not sure if bgcolor should just be 0, but since bgcolor is never set != 0, it doesn't matter
    if (this.info.fgcolor != this.info.bgcolor) {
      this._drawpoly(numpoints, pp);
    }
  }

/*
  // TO REMOVE
  // TEST: FLIPPING x & y axis (SAME ISSUE AS ABOVE)
  fillpoly2 (numpoints, pp) {
    // polypoints array of ints

    // code based on: http://alienryderflex.com/polygon_fill/
    const vp = this.info.vp;
    let i, j, x, y, ynode, yval;

    // scan thru all rows in viewport
    for (x = vp.left; x <= vp.right; x++) {
      ynode = [];

      // build node list
      j = numpoints - 1;
      for (i=0; i < numpoints; i++) {
        if ( ((pp[i*2] < x) && (pp[j*2] >= x)) || ((pp[j*2] < x) && (pp[i*2] >= x)) ) {

          yval = (x - pp[i*2]) / (pp[j*2] - pp[i*2]) * (pp[j*2+1] - pp[i*2+1]) + pp[i*2+1];
          //ynode.push( Math.round(yval) );
          //ynode.push( Math.floor(yval) );
          //ynode.push( Math.trunc(yval) );
          ynode.push( Math.ceil(yval) );
        }
        j = i;
      }

      // sort nodes
      if (ynode.length == 0) continue;
      ynode.sort(function(a, b) { return a - b; });

      // draw pixels between node pairs
      for (i=0; i < ynode.length; i+=2) {
        // clip to viewport edges
        if (ynode[i] > vp.bottom) break;
        if (ynode[i+1] >= vp.top) {
          if (ynode[i+1] > vp.bottom) { ynode[i+1] = vp.bottom; }
          if (ynode[i] < vp.top) { ynode[i] = vp.top; }
          for (y = ynode[i]; y <= ynode[i+1]; y++) {
            this.ff_putpixel(x, y, this.info.fill.color, BGI.COPY_PUT);
          }
        }
      }
    }
    // this fixes filled polygon border issues
    if (this.info.fgcolor !== this.info.bgcolor) {
      this.drawpoly(numpoints, pp);
    }
  }
*/

  // NOT IN SDL_bgi ?
  // Draws and fills a rectangle, using fgcolor, line & fill style. Includes border.
  fillrect (left, top, right, bottom) {
    this.bar(left, top, right, bottom);
    this.rectangle(left, top, right, bottom);
  }
  _fillrect (left, top, right, bottom) {
    this._bar(left, top, right, bottom);
    this._rectangle(left, top, right, bottom);
  }

  // Flood Fill
  // uses info.fill.color, info.fill.fpattern, & info.bgcolor.
  // uses info.writeMode but not sure if it should.
  // border or color = boundry color.
  // Returns if (x0, y0) is outside current viewport, or is already border color.
  // Clears then writes pixels of color 0xFF to this.fillpixels[]
  // which can be read after return to find out the fill area.
  // This floods horizontally before vertically in order to support a bug.

  floodfill (x0, y0, border) {
    this._floodfill(x0, y0, border);
  }
  _floodfill (x0, y0, border) {

    const vp = this.info.vp;
    this.fillpixels.fill(0);

    if ((x0 < 0) || (x0 >= vp.width ) || (y0 < 0) || (y0 >= vp.height)) { return }
    if (this.getpixel(x0, y0) === border) { return }

    let stack = [];
    stack.push([x0, y0]);
    let popped, count = 0;

    while (popped = stack.pop()) {
      let [x, y] = popped;
      let x1 = x, x2 = x + 1;

      // find left end of line (x1)
      while ((x1 >= 0) && (this.getpixel(x1, y) != border)) { x1--; }
      x1++;
      // find right end of line (x2)
      while ((x2 < vp.width) && (this.getpixel(x2, y) != border)) { x2++; }
      x2--;

      let spanUp = false, spanDown = false;
      for (x = x1; x <= x2; x++) {
        this.ff_putpixel(x, y, this.info.fill.color, BGI.COPY_PUT);
        this._putpixel(x, y, BGI.WHITE, BGI.COPY_PUT, this.fillpixels);
        count++;

        // intentional bug to prevent flooding up or down
        // through pixels along edges of viewport.
        if ((x <= 0) || (x >= vp.width - 1)) { continue }

        if (!spanUp && (y > 0)
          && (this.getpixel(x, y-1) !== border)
          && (this.getpixel(x, y-1, this.fillpixels) !== BGI.WHITE)) {
          stack.push([x, y-1]);
          spanUp = true;
        }
        else if (spanUp && (y > 0) && (this.getpixel(x, y-1) === border)) {
          spanUp = false;
        }

        if (!spanDown && (y < vp.height - 1)
          && (this.getpixel(x, y+1) != border)
          && (this.getpixel(x, y+1, this.fillpixels) !== BGI.WHITE)) {
          stack.push([x, y+1]);
          spanDown = true;
        }
        else if (spanDown && (y < vp.height - 1) && (this.getpixel(x, y+1) === border)) {
          spanDown = false;
        }
      }
    }
  }

  getarccoords (arccoords) { // ***
    // struct arccoordstype *arccoords
  }

  // returns an object {xasp:int, yasp:int}
  getaspectratio (/* xasp, yasp */) { // ***
    // int *xasp, int *yasp
    return this.aspect;
  }

  getbkcolor (/* void */) {
    return this.info.bgcolor; // int
  }

  getcolor (/* void */) {
    return this.info.fgcolor; // int
  }

  getdefaultpalette (/* void */) {
    // return struct palettetype*
    return BGI.ega_palette; // TODO: might change
  }

  // not implemented (STUB)
  getdrivername () {
    this.unimplemented('getdrivername');
    return '';
  }

  getfillpattern (pattern) { // ***
    // char *pattern
  }

  getfillsettings (/* fillinfo */) { // ***
    // struct fillsettingstype *fillinfo
    return this.info.fill;
  }

  getgraphmode (/* void */) {
    return 0; // int
  }

  // Grab and return a byte array of image data.
  // returns this image object:
  // { x:int, y:int, width:int, height:int, data:Uint8ClampedArray }
  // uses this.pixels[]
  getimage (left, top, right, bottom) {
    return this._getimage(left, top, right, bottom);
  }
  _getimage (left, top, right, bottom) {
    // void *bitmap

    // swap
    if (left > right) { let tmp = left; left = right; right = tmp; }
    if (top > bottom) { let tmp = top; top = bottom; bottom = tmp; }

    let w = (right - left + 1), h = (bottom - top + 1);
    let data = new Uint8ClampedArray(w * h);
    let i, o=0;
    for (let y=top; y <= bottom; ++y) {
      i = y * this.width + left;
      for (let x=left; x <= right; ++x) {
        data[o++] = this.pixels[i++];
      }
    }
    return {x: left, y: top, width: w, height: h, data: data};
  }

  getlinesettings (/* lineinfo */) { // ***
    // struct linesettingstype *lineinfo
    return this.info.line;
  }

  getmaxcolor (/* void */) {
    return BGI.MAXCOLORS; // ok for now
  }

  getmaxmode (/* void */) {
    return 0; // int
  }

  getmaxx (/* void */) {
    return this.width - 1; // int
  }

  getmaxy (/* void */) {
    return this.height - 1; // int
  }

  getmodename (mode_number) {
    return '';
  }

  getmoderange(graphdriver, lomode, himode) {
    // int graphdriver, int *lomode, int *himode
  }

  getpalette (/* palette */) { // ***
    // struct palettetype *palette
    // C++ version fills pointer to palette struct provided, returns void.
    // js version returns a RGBA32 palette array.
    return this.palette;
  }

  // returns number of colors in the RGBA32 palette array
  getpalettesize (/* void */) {
    return Math.floor(this.palette.length / 4); // int
  }

  // Get pixel NOT offset by current viewport, else return 0.
  // (if changed to offset, remember to fix it in floodfill().)
  getpixel (x, y, buf = this.pixels) {

    // since this is only called from floodfill(), we know they're already ints
    // x = Math.round(x);
    // y = Math.round(y);

    // offset by viewport
    const vp = this.info.vp;
    x += vp.left;
    y += vp.top;

    if ((x >= 0) && (x < this.width) && (y >= 0) && (y < this.height)) {
      return buf[y * this.width + x]; // int
    }
    return 0; // ???
  }

  // returns object: { font: 0, direction: 0, charsize: 0, horiz: 0, vert: 0 }
  gettextsettings () {
    // OLD struct textsettingstype *texttypeinfo
    return JSON.parse(JSON.stringify(this.info.text)); // copy obj
  }

  getviewsettings (viewport) { // ***
    // struct viewporttype *viewport
  }

  getx (/* void */) {
    return this.info.cp.x; // int
  }

  gety (/* void */) {
    return this.info.cp.y; // int
  }

  graphdefaults (/* void */) {
    /*
    graphdefaults resets all graphics settings to their defaults:
      sets the viewport to the entire screen.
      moves the current position to (0,0).
      sets the default palette colors, background color, and drawing color.
      sets the default fill style and pattern.
      sets the default text font and justification.
    */

    this.info = this.infoDefaults();

    // RGBA32 [r, g, b, a] same as npm's canvas
    //this.palette = new Uint8ClampedArray(256 * 4);
    this.palette = Uint8ClampedArray.from(BGI.ega_palette); // for now
  }

  // not implemented (STUB)
  grapherrormsg (errorcode) {
    // errorcode from graphresult() return value.
    this.unimplemented('grapherrormsg');
    return '';
  }

  // not implemented (STUB)
  graphresult (/* void */) {
    // returns error code for the last graphics operation that reported an error
    this.unimplemented('graphresult');
    return 0;
  }

  // returns size of memory in bytes required to store a bit image
  imagesize (x1, y1, x2, y2) {
    this.unimplemented('imagesize');
    return 0; // unsigned int
  }


  // initializes the graphics system (old DOS way)
  // don't implement? (STUB)
  initgraph (width, height, pathtodriver) { // modified args
    // int *graphdriver, int *graphmode, char *pathtodriver // original args
    // pathtodriver = is also path to *.CHR files
    // in Windows instead use: initwindow(w, h)
    this.unimplemented('initgraph');
  }

  // don't implement (STUB)
  // old DOS function
  installuserdriver () {
    // char *name, int huge (*detect)(void)
    this.unimplemented('installuserdriver');
    return 0;
  }

  // Installs a font file from disk.
  // name = filename of font .CHR file (e.g. "USER.CHR") not including path?
  // returns a font ID that can be passed to settextstyle()
  // TODO: not done!
  installuserfont (name) {

    let idnum = BGI.fontFileIds[name];
    // TODO: load font file?
    return (idnum) ? idnum : 0; // TEST
  }

  // Draws a line in the current color, using the current write mode, line style, and thickness
  // between the two points without updating the current position (info.cp).
  line (x1, y1, x2, y2, color = this.info.fgcolor, wmode = this.info.writeMode,
        linestyle = this.info.line.style, thickness = this.info.line.thickness) {
    this._line(x1, y1, x2, y2, color, wmode, linestyle, thickness);
  }
  _line (x1, y1, x2, y2, color = this.info.fgcolor, wmode = this.info.writeMode,
        linestyle = this.info.line.style, thickness = this.info.line.thickness) {
    // TODO: pass in 'linesettingstype' object instead?

    // if y2 < y1, swap points
    if (y2 < y1) {
      let tmp = y1; y1 = y2; y2 = tmp;
      tmp = x1; x1 = x2; x2 = tmp;
    }

    // TEST
    // Trying to fix bottom edge lines in FRACTMTN.RIP
    // if points outside viewport, move to inside. Not sure if this should be canvas bounds.
    // Moving point inside viewport fixes RIPDELIC.RIP
    const vp = this.info.vp;
    if (y1 < vp.top) { y1 = vp.top; }
    if (y1 > vp.bottom) { return; }
    if (y2 < vp.top) { return; }
    //if (y2 > vp.bottom) { y2 = vp.bottom + 1; }
    if (y2 > vp.bottom) { y2 = vp.bottom; }
    if (x1 < vp.left) { x1 = vp.left; }
    if (x1 > vp.right) { return; }
    if (x2 < vp.left) { return; }
    if (x2 > vp.right) { x2 = vp.right; }

    // set pattern
    // TODO: should this be here, or passed into line()?
    let upattern = (linestyle < BGI.USERBIT_LINE) ? BGI.line_patterns[linestyle] : this.info.line.upattern;

    // first line
    this.line_bresenham(x1, y1, x2, y2, color, wmode, upattern);

    // 2nd & 3rd lines
    if (thickness === BGI.THICK_WIDTH) {
      const oct = this.octant(x2 - x1, y1 - y2);
      if ((oct === 1) || (oct === 4) || (oct ===5) || (oct === 8)) {
        this.line_bresenham(x1, y1 - 1, x2, y2 - 1, color, wmode, upattern);
        this.line_bresenham(x1, y1 + 1, x2, y2 + 1, color, wmode, upattern);
      }
      else {
        this.line_bresenham(x1 - 1, y1, x2 - 1, y2, color, wmode, upattern);
        this.line_bresenham(x1 + 1, y1, x2 + 1, y2, color, wmode, upattern);
      }
    }
  }

  // Draws a line from the CP (current position) to a point that is a relative distance (dx,dy)
  // from the CP. The CP is advanced by (dx,dy).
  linerel (dx, dy, color = this.info.fgcolor, wmode = this.info.writeMode,
        linestyle = this.info.line.style, thickness = this.info.line.thickness) {

    this.line(this.info.cp.x, this.info.cp.y, this.info.cp.x + dx, this.info.cp.y + dy,
      color, wmode, linestyle, thickness);
    this.info.cp.x += dx;
    this.info.cp.y += dy;
  }

  // Draws a line from the CP (current position) to (x,y), then moves the CP to (x,y).
  lineto (x, y, color = this.info.fgcolor, wmode = this.info.writeMode,
        linestyle = this.info.line.style, thickness = this.info.line.thickness) {

    this.line(this.info.cp.x, this.info.cp.y, x, y, color, wmode, linestyle, thickness);
    this.info.cp.x = x;
    this.info.cp.y = y;
  }
  _lineto (x, y, color = this.info.fgcolor, wmode = this.info.writeMode,
        linestyle = this.info.line.style, thickness = this.info.line.thickness) {

    this._line(this.info.cp.x, this.info.cp.y, x, y, color, wmode, linestyle, thickness);
    this.info.cp.x = x;
    this.info.cp.y = y;
  }

  // Moves the CP by (dx, dy) pixels.
  moverel (dx, dy) {
    this.info.cp.x += dx;
    this.info.cp.y += dy;
  }

  // Moves the CP to the position (x, y) relative to the viewport.
  moveto (x, y) {
    this.info.cp.x = x;
    this.info.cp.y = y;
  }

  // Draws text using current info.cp position, info.text.charsize and info.text.direction
  outtext (text) {
    this._outtext(text);
  }
  _outtext (text) {

    if (typeof text !== "string") { return }
    // set yoffset for scalable fonts first
    let yoffset = 0;
    const fontnum = this.info.text.font;
    const scale = this.info.text.charsize;

    if (fontnum === BGI.DEFAULT_FONT) {
      // move cp if vertical
      if (this.info.text.direction === BGI.VERT_DIR) {
        this.moverel(-this.textheight(text), this.textwidth(text) - scale);
      }
      // loop thru each character in text string
      text.split('').forEach(c => {
        const cvalue = c.charCodeAt(0) & 0xFF; // to strip out 2nd byte
        this.drawPNGChar(cvalue, 0, this.info.text.charsize, this.info.text.direction);
      });
    }
    else if (fontnum < BGI.fontFileList.length) {
      const fontname = BGI.fontFileList[fontnum];
      if (this.info.text.direction === BGI.HORIZ_DIR) {
        this.moverel(0, this.textheight(text));
      }
      else {
        this.moverel(this.textheight(text), this.textwidth(text));
      }

      // loop thru each character in text string
      text.split('').forEach(c => {
        const cvalue = c.charCodeAt(0) & 0xFF; // to strip out 2nd byte
        this.drawChar(cvalue, fontname, scale, this.info.text.direction);
      });
    }
  }

  // Draws text after offseting position by (x, y).
  outtextxy (x, y, text) {
    this.moveto(x, y);
    this.outtext(text);
  }
  _outtextxy (x, y, text) {
    this.moveto(x, y);
    this._outtext(text);
  }

  // fills with fill color and pattern.
  // outlines with fgcolor and thickness. doesn't use line style.
  pieslice (cx, cy, stangle, endangle, radius) {
    if ((radius < 1) || (stangle === endangle)) { return; } // TODO: test against reference
    // adjust radius based on aspect ratio
    const yradius = Math.floor( radius * (this.aspect.xasp / this.aspect.yasp) );
    this.sector(cx, cy, stangle, endangle, radius, yradius);
  }
  _pieslice (cx, cy, stangle, endangle, radius) {
    if ((radius < 1) || (stangle === endangle)) { return; } // TODO: test against reference
    const yradius = Math.floor( radius * (this.aspect.xasp / this.aspect.yasp) );
    this._sector(cx, cy, stangle, endangle, radius, yradius);
  }

  // Put byte array of image data on to pixels[]
  // image is an object:
  //   { x:int, y:int, width:int, height:int, data:Uint8ClampedArray }
  // wmode = write mode { 0=COPY_PUT, 1=XOR_PUT, 2=OR_PUT, 3=AND_PUT, 4=NOT_PUT }
  // Image is offset by viewport.
  // May draw past right edge of vp, or exits early if past right edge of canvas.
  // Will draw past bottom edge of vp and clip at edge of canvas.
  //
  putimage (left, top, image, wmode, defaults = {}) {
    return this._putimage(left, top, image, wmode, defaults);
  }
  _putimage (left, top, image, wmode, defaults = {}) {

    // ORIGINAL
    // putimage (left, top, bitmap, op)
    //   bitmap = points to where image stored
    //   op = see putimage_ops enum (0=COPY, 1=XOR, 2=OR, 3=AND, 4=NOT)

    let {
      pix = this.pixels,
      info = this.info,
      width = this.width,
      height = this.height,
      colorMask = this.colorMask,
    } = defaults;

    if (!(image && image.data)) {
      this.log('err', 'putimage() missing image!');
      return false;
    }

    // offset by viewport
    if (info && info.vp) {
      left += info.vp.left;
      top += info.vp.top;
    }

    let data = image.data; // Uint8ClampedArray
    let right = left + image.width - 1;
    let bottom = top + image.height - 1;
    let i=0, o=0, yi=0;

    // NOTE: the follow occurs under RIP 1.54
    //  but may clip to viewport under 2.0?
    //  (according to specs, not tested.)

    // exit early if image past right edge of canvas
    if (right > width - 1) {
      this.log('bgi', `putimage() ignored: right edge (${right}) past canvas edge.`);
      return false;
    }
    // draws past bottom of vp, but clips at canvas bottom
    if (bottom > height - 1) { bottom = height - 1; }

    switch (wmode) {
      default:
      case BGI.COPY_PUT:
        for (let y=top; y <= bottom; ++y) {
          i = yi * image.width;
          o = y * width + left;
          for (let x=left; x <= right; ++x) {
            pix[o++] = data[i++];
          }
          yi++;
        }
        break;

      case BGI.XOR_PUT:
        for (let y=top; y <= bottom; ++y) {
          i = yi * image.width;
          o = y * width + left;
          for (let x=left; x <= right; ++x) {
            pix[o++] ^= data[i++];
          }
          yi++;
        }
        break;

      case BGI.OR_PUT:
        for (let y=top; y <= bottom; ++y) {
          i = yi * image.width;
          o = y * width + left;
          for (let x=left; x <= right; ++x) {
            pix[o++] |= data[i++];
          }
          yi++;
        }
        break;

      case BGI.AND_PUT:
        for (let y=top; y <= bottom; ++y) {
          i = yi * image.width;
          o = y * width + left;
          for (let x=left; x <= right; ++x) {
            pix[o++] &= data[i++];
          }
          yi++;
        }
        break;

      case BGI.NOT_PUT:
        for (let y=top; y <= bottom; ++y) {
          i = yi * image.width;
          o = y * width + left;
          for (let x=left; x <= right; ++x) {
            pix[o++] = ~data[i++] & colorMask;
          }
          yi++;
        }
    }
    return true;
  }

  // returns a non-uniform random integer >= 0 and < range.
  random (range) {
    return Math.floor(Math.random() * range);
  }

  // Fetch an ICN file from cache or online.
  // returns an image or empty object
  //
  async readimagefile (filename) {

  // readimagefile (filename, left, top, right, bottom) {
    // const char* filename=NULL, int left=0, int top=0, int right=INT_MAX, int bottom=INT_MAX
    // reads a BMP, GIF, JPG, ICON, EMF, or WMF image file.
    // displays it in part of the current active window.
    // unknown: would x2,y2 crop the image if under size of image? (I'd think so)
    // NOT DONE: need to check BGI spec!

    // retrieve image from cache first, if available
    let image = this.icons[filename];
    if (!image) {
      const url = this.iconsPath + '/' + filename;
      image = await this.fetchIcon(url);
      if (image) {
        // store in cache
        this.icons[filename] = image;
      }
    }
    // this doesn't display the image, but instead returns it for use in putimage()
    return image || {};
  }

  // draws in current line style, thickness, and drawing color
  rectangle (left, top, right, bottom, color = this.info.fgcolor, wmode = this.info.writeMode) {
    this._rectangle(left, top, right, bottom, color, wmode);
  }
  _rectangle (left, top, right, bottom, color = this.info.fgcolor, wmode = this.info.writeMode) {

    // swap
    if (left > right) { let tmp = left; left = right; right = tmp; }
    if (top > bottom) { let tmp = top; top = bottom; bottom = tmp; }
    this._line(left, top, right, top, color, wmode);
    this._line(right, top, right, bottom, color, wmode);
    this._line(right, bottom, left, bottom, color, wmode);
    this._line(left, bottom, left, top, color, wmode);
  }

  // don't implement these (DOS only) (STUB)
  registerbgidriver () {
    // registerbgidriver(void (*driver)(void)) { }
    this.unimplemented('registerbgidriver');
  }
  registerbgifont () {
    //registerbgifont(void (*font)(void)) { }
    this.unimplemented('registerbgifont');
  }

  // don't implement (DOS only) (STUB)
  // only makes sense when switching to text mode in DOS.
  restorecrtmode (/* void */) {
    this.unimplemented('restorecrtmode');
  }

  // Draws and fills an elliptical pie slice centered at (x, y).
  _sector (cx, cy, stangle, endangle, xradius, yradius) {
    this.sector(cx, cy, stangle, endangle, xradius, yradius);
  }
  sector (cx, cy, stangle, endangle, xradius, yradius) {
    // Outlines using current color, filled using pattern & color.
    // from setfillstyle & setfillpattern.
    // Angles in degrees, counter-clockwise: 0=3o'clock, 90=12o'clock

    // draw a single pixel at center instead
    if (stangle === endangle) {
      this.putpixel(cx, cy);
      return;
    }

    // TODO: don't know if these are correct, or should we exit?
    if (xradius < 1) { xradius = 1; }
    if (yradius < 1) { yradius = 1; }

    // swap if start > end (unlike elliptical arcs!)
    // some pies may not be represented if angles > 360 aren't allowed!
    if (stangle > endangle) { let t = stangle; stangle = endangle; endangle = t; }

    // draw outline
    // (doesn't use same algorithm as thick ellipses!)
    //this._ellipse(cx, cy, stangle, endangle, xradius, yradius);
    this.arc_bresenham(cx, cy, stangle, endangle, xradius, yradius);

    // calculate start and end pixels for pie wedge
    let endangle2 = endangle % 360; // fix for specific case
    const twoPiD = 2 * Math.PI / 360;
    let x1 = cx + Math.floor(xradius * Math.cos(stangle * twoPiD));
    let y1 = cy - Math.floor(yradius * Math.sin(stangle * twoPiD));
    let x2 = cx + Math.floor(xradius * Math.cos(endangle2 * twoPiD));
    let y2 = cy - Math.floor(yradius * Math.sin(endangle2 * twoPiD));

    // draw pie wedge lines to center
    this._line(cx, cy, x1, y1);
    this._line(cx, cy, x2, y2);

    // TODO: Pie filling
    // May need something like a modified fillellipse_bresenham()
    // which I haven't figured out how to do yet...

    // Alternately use floodfill, which is not perfect.
    // also floodfill likely wouldn't work in XOR_PUT write mode!

    // To pick a point for the floodfill, take the angle
    // halfway between the start & end, find that point
    // along ellipse, then take midpoint to center.

    // fill pie using floodfill
    let half_angle = (endangle - stangle) / 2 + stangle; // assuming stangle < endangle
    let fx = Math.round((xradius * Math.cos(half_angle * twoPiD)) / 2 + cx);
    let fy = Math.round((yradius * -Math.sin(half_angle * twoPiD)) / 2 + cy);
    this._floodfill (fx, fy, this.info.fgcolor);
  }

  // active page where all subsequent graphics output goes
  setactivepage (page) {
  }

  setallpalette (p) {
    // void setallpalette (struct palettetype *palette);
    // This is a little different and might not want to use?
    // To set a palette color to an RGB color, use setrgbpalette()
  }

  // Used to make sure circles are round.
  // SEE getaspectratio()
  setaspectratio (xasp, yasp) {
    this.aspect = { xasp: xasp, yasp: yasp };
  }

  // sets background to given index color.
  // WIN version doesn't change the 0 pallete, so it's not instant,
  // but any future drawings will use what is currently in color at index [color].
  setbkcolor (color) {
    this.info.bgcolor = color;
  }

  // sets current drawing color: 0 to getmaxcolor(), usually 0-15. WIN allows RGB COLOR()
  setcolor (color) {
    this.info.fgcolor = color;
  }

  // fpattern is an array of 8 ints (0-255), each byte is 8 pixels in the pattern.
  setfillpattern (fpattern, color) {
    // void setfillpattern(char *upattern, int color);
    this.info.fill.color = color;
    if (fpattern && (fpattern.length === 8)) {
      this.info.fill.style = BGI.USER_FILL;
      this.info.fill.fpattern = fpattern;
    }
  }

  // style is index 0-11 into fill_patterns[]
  // pattern bits 0=current bg color, 1=color (passed in, not global color)
  setfillstyle (style, color) {

    this.info.fill.color = color;
    if ((style >= 0) && (style <= BGI.USER_FILL)) {
      this.info.fill.style = style;
      this.info.fill.fpattern = BGI.fill_patterns[style];
    }
  }

  // don't implement (STUB)
  setgraphbufsize (size) {
    // unsigned setgraphbufsize(unsigned bufsize);
    // tells how much memory to allocate for internal graphics buffer used by floodfill.
    this.unimplemented('setgraphbufsize');
  }

  // don't implement?? (STUB)
  setgraphmode (mode) {
    // selects graphics mode different than default set by initgraph()
    // clears screen, resets graphics to defaults.
    this.unimplemented('setgraphmode');
  }

  setlinestyle (linestyle, upattern = 0xFFFF, thickness = 1) {
    // void setlinestyle (int linestyle, unsigned upattern, int thickness);
    // linestyle value from line_styles enum.
    // upattern is a 16-bit int (e.g. 0xFFFF is a solid line).
    // upattern only applies if linestyle == 4 (USERBIT_LINE)
    // The linestyle doesn't effect arcs, circles, ellipses, or pie slices. Only thickness does.

    this.info.line.style = linestyle;
    this.info.line.upattern = upattern;
    this.info.line.thickness = thickness;
  }

  // Set colornum index in palette (0-15) to color (0-15), or RGB color using COLOR() in WIN.
  // TODO: should we support EGA palette colors (0-63)?
  setpalette (colornum, color) {

    if (BGI.IS_RGB_COLOR(color)) {
      this.setrgbpalette(colornum, BGI.RED_VALUE(color), BGI.GREEN_VALUE(color), BGI.BLUE_VALUE(color));
    }
    else if (BGI.IS_BGI_COLOR(color)) {
      // lookup bgi color
      const c = BGI.bgi_palette[color];
      this.setrgbpalette(colornum, BGI.RED_VALUE(c), BGI.GREEN_VALUE(c), BGI.BLUE_VALUE(c));
    }
    else {
      this.log('bgi', `setpalette() color [${color}] not supported!`);
    }
  }

  // colornum can be 0-15 or 0-255 depending on the grapics mode?
  // red, green, blue are 0-255, but only the top 6 bits of byte are used in BGI and WIN.
  // (but this implementation will use all 8 bits.)
  setrgbpalette (colornum, red, green, blue, alpha = 255, defaults = {}) {
    // setrgbpalette(int colornum, int red, int green, int blue);
    const {
      pal = this.palette
    } = defaults;

    const pi = colornum * 4;
    if (pi < pal.length) {
      // RGBA32 format: R, G, B, A
      pal[pi + 0] = red;
      pal[pi + 1] = green;
      pal[pi + 2] = blue;
      pal[pi + 3] = alpha;
    }
    else {
      this.log('bgi', `setrgbpalette() colornum [${colornum}] out of range!`);
    }
  }

  // TODO
  // affects text written
  settextjustify (horiz, vert) {
    // SEE text_just enums
    // SEE https://www.cs.colorado.edu/~main/bgi/doc/settextjustify.html
    // Text output after a call to settextjustify is justified around the current position (CP) horizontally 
    // and vertically, as specified. The default justification settings are LEFT_TEXT (for horizontal) and 
    // TOP_TEXT (for vertical). If horiz is equal to LEFT_TEXT and direction equals HORIZ_DIR, the CP's 
    // x component is advanced after a call to outtext(string) by textwidth(string).
  }

  settextstyle (font, direction, charsize) {
    // SEE https://www.cs.colorado.edu/~main/bgi/doc/settextstyle.html
    this.info.text.font = font;
    this.info.text.direction = direction;
    this.info.text.charsize = charsize;
  }

  // TODO
  setusercharsize (multx, divx, multy, divy) {
    // setusercharsize gives finer control over the size of text from stroked fonts.
    // SEE https://www.cs.colorado.edu/~main/bgi/doc/setusercharsize.html
  }

  // Sets the current viewport for graphics output.
  // Current position (CP) is moved to (0,0) in the new window. (so coords change??)
  // If clip is true, all drawings will be clipped to the current viewport.
  setviewport (x1, y1, x2, y2, clip) {

    // swap
    if (x1 > x2) { let tmp = x1; x1 = x2; x2 = tmp; }
    if (y1 > y2) { let tmp = y1; y1 = y2; y2 = tmp; }

    if ((x1 >= 0) && (x2 < this.width) && (y1 >= 0) && (y2 < this.height)) {
      this.info.vp.left = x1;
      this.info.vp.top = y1;
      this.info.vp.right = x2;
      this.info.vp.bottom = y2;
      this.info.vp.width = x2 - x1 + 1;
      this.info.vp.height = y2 - y1 + 1;
      this.info.vp.clip = clip;
      this.info.cp.x = 0;
      this.info.cp.y = 0;
      this.log('bgi', `viewport set to (${x1},${y1})-(${x2},${y2})`); // DEBUG
    }
  }

  _resetViewport () {
    this.info.vp = { left: 0, top: 0, right: (this.width - 1), bottom: (this.height - 1),
      width: this.width, height: this.height, clip: false };
  }

  // likely won't implement (STUB)
  // makes page the visual graphics page.
  setvisualpage (page) {
    this.unimplemented('setvisualpage');
  }

  // mode = COPY_PUT (0), XOR_PUT (1)
  // works only with line(), linerel(), lineto(), rectangle(), drawpoly()
  setwritemode (mode) {
    //this.log('bgi', `write mode set to ${mode}`); // DEBUG
    this.info.writeMode = mode;
  }

  // check every char in text and return true if at least 1 char has a descender.
  _textHasDescender (text) {
    if (typeof text !== "string") { return false; }
    let drop = false;
    text.split('').forEach(c => {
      let cvalue = c.charCodeAt(0) & 0xFF; // strip out 2nd byte
      if (BGI.charDescenders[cvalue] === 1) { drop = true; }
    });
    return drop;
  }

  // Takes current font size and multiplication factor, and determines the height of text in pixels.
  // flags (optional):
  //   0 = BGI.VAR_HEIGHT:
  //       Scans text for any descenders and returns full height if there are any, else only main height.
  //   1 = BGI.MAIN_HEIGHT: Returns main height between the baseline and top of text.
  //   2 = BGI.DROP_HEIGHT: Returns drop height from the baseline to bottom of descender.
  //   3 = BGI.FULL_HEIGHT: Returns main + drop height. (default)
  //   4 = BGI.ABOVE_HEIGHT: Returns distance from top of cell to top of text.

  textheight (text, flags = BGI.FULL_HEIGHT) {

    if (typeof text !== "string") { return 0; }
    let th = 0;
    const fontnum = this.info.text.font;
    const scale = this.info.text.charsize;

    if (fontnum === BGI.DEFAULT_FONT) {
      let dropv = this._textHasDescender(text) ? 1 : 0;
      switch (flags) {
        case BGI.VAR_HEIGHT: th = scale * (8 + dropv); break;
        case BGI.MAIN_HEIGHT: th = scale * 8; break;
        case BGI.DROP_HEIGHT: th = scale * dropv; break;
        case BGI.ABOVE_HEIGHT: th = 0; break;
        default: th = scale * 8; // full height
      }
    }
    else if (fontnum < BGI.fontFileList.length) {
      const fontname = BGI.fontFileList[fontnum];
      const font = this.fonts[fontname];
      if (font) {
        const actualScale = (scale < BGI.fontScales.length) ? BGI.fontScales[scale] : 1;
        let h = 0;

        switch (flags) {
          case BGI.VAR_HEIGHT:
            // check every char in text and add drop height only if at least 1 char has a descender.
            h = this._textHasDescender(text) ? (font.top - font.bottom) : font.top;
            th = Math.trunc(h * actualScale);
            break;

          case BGI.MAIN_HEIGHT:
            th = Math.trunc(font.top * actualScale);
            break;

          case BGI.DROP_HEIGHT:
            th = Math.trunc(-font.bottom * actualScale);
            break;

          case BGI.ABOVE_HEIGHT:
            th = Math.round(BGI.fontPadAbove[fontnum] * actualScale); // round() or trunc()??
            break;

          default: // BGI.FULL_HEIGHT
            th = Math.trunc((font.top - font.bottom) * actualScale);
        }
      }
    }
    return th;
  }
  
  // takes the string length, current font size, and multiplication factor, and determines the width of text in pixels.
  textwidth (text) {

    if (typeof text !== "string") { return 0; }
    let tw = 0;
    const fontnum = this.info.text.font;
    const scale = this.info.text.charsize;

    if (fontnum === BGI.DEFAULT_FONT) {
      tw = text.length * 8 * scale;
    }
    else if (fontnum < BGI.fontFileList.length) {
      // CHR font widths
      const fontname = BGI.fontFileList[fontnum];
      const font = this.fonts[fontname];
      if (font) {
        const actualScale = (scale < BGI.fontScales.length) ? BGI.fontScales[scale] : 1;
        // loop thru each character in text string
        text.split('').forEach(c => {
          let cvalue = c.charCodeAt(0) & 0xFF; // to strip out 2nd byte
          let charwidth = font.widths[cvalue - font.firstchar];
          tw += (charwidth * actualScale);
        });
      }
    }
    return Math.floor(tw);
  }

  ////////////////////////////////////////////////////////////////////////////////
  // BGI for Windows Color functions

  // Do we need EGA palette specific functions?
  // Didn't check this code against reference.

  // r,g,b range 0-255
  // returns packed ARGB values (0xAARRGGBB) in C++ style
  static COLOR (r, g, b) {
    return 0xFF000000 | r << 16 | g << 8 | b;
  }

  static IS_BGI_COLOR (color) {
    return ((color >= 0) && (color < 16));
  }

  static IS_RGB_COLOR (color) {
    return ((color & 0xFF000000) !== 0);
  }

  // these return the color component of an RGB color
  static RED_VALUE (rgb_color) {
    return (rgb_color & 0xFF0000) >> 16;
  }

  static GREEN_VALUE (rgb_color) {
    return (rgb_color & 0xFF00) >> 8;
  }

  static BLUE_VALUE (rgb_color) {
    return (rgb_color & 0xFF);
  }

  ////////////////////////////////////////////////////////////////////////////////
  // BGI for Windows functions (some not present)

  closegraph (win) {
    this.refresh();
  }

  // NOT IN SDL_bgi
  // returns an RGB color from a BGI/index color
  converttorgb (color) {
    return 0;
  }

  async delay (millisec) {
    return new Promise(resolve => setTimeout(resolve, millisec));
  }

  getactivepage (/* void */) {
    this.unimplemented('getactivepage');
  }

  getdisplaycolor () {

  }

  // EXTRA
  getch (/* void */) {
    this.unimplemented('getch');
    return 0; // int
  }
  bgi_getch = this.getch;

  getvisualpage (/* void */) {
    this.unimplemented('getvisualpage');
    return 0; // int
  }

  // returns true if a char is in input buffer, else false.
  kbhit (/* void */) {
    return false; // int
  }

  // returns the most recent x coord of the mouse within the graphics window.
  mousex (/* void */) {
    return this.mouseX;
  }

  // returns the most recent y coord of the mouse within the graphics window.
  mousey (/* void */) {
    return this.mouseY;
  }

  // Calculate mouse coordinates.
  // provided Event e, returns translated mouse coords [x, y]
  // FIXME: may be off by 1 pixel?
  //
  _mouseCoords (e) {
    // clientWidth or Height could be off if canvas padding not 0?
    const x = Math.floor(e.offsetX * (e.target.width / e.target.clientWidth)) - 1;
    const y = Math.floor(e.offsetY * (e.target.height / e.target.clientHeight)) - 1;
    return [x, y];
  }

  // 2nd arg is a callback function sent x,y mouse position,
  // which is currently called whenever the mouse moves over canvas.
  // 'kind' is an object, see "mouse events" class constants.
  // original: registermousehandler(int kind, void h(int x, int y));
  //
  registermousehandler (kind, callback) {
    const etype = kind.type || '';
    const ebutton = kind.button;
    if ((typeof callback === 'function') && (etype !== '')) {
      this.ctx.canvas.addEventListener(etype, (e) => {
        if ((e.button === ebutton) || (ebutton === -1)) {
          const [x, y] = this._mouseCoords(e);
          callback(x, y);
        }
      });
    }
  }

  setcurrentwindow (win) {
    // not really needed? Assumes multiple windows.
    // We can use multiple instances of the BGI class, one for each canvas,
    // instead of using multiple windows.
    this.unimplemented('setcurrentwindow');
  }

  // NOT IN BGI nor WIN EXTRA, but from WinGraph (not official)
  // floodmode = 
  //   BORDER_FLOOD: fill area is bounded by color
  //   SURFACE_FLOOD: fill regions containing color
  setfloodmode (floodmode) {

  }

}

////////////////////////////////////////////////////////////////////////////////

if (typeof window !== 'undefined') {
  window.BGI = BGI;
}
// class constants

  // fonts
  BGI.DEFAULT_FONT=0; BGI.TRIPLEX_FONT=1; BGI.SMALL_FONT=2; BGI.SANSSERIF_FONT=3;
  BGI.GOTHIC_FONT=4; BGI.SCRIPT_FONT=5; BGI.SIMPLEX_FONT=6; BGI.TRIPLEX_SCR_FONT=7;
  BGI.COMPLEX_FONT=8; BGI.EUROPEAN_FONT=9; BGI.BOLD_FONT=10;

  // text alignment used in settextjustify()
  BGI.HORIZ_DIR=0; BGI.VERT_DIR=1; // text_just enum
  BGI.LEFT_TEXT=0; BGI.CENTER_TEXT=1; BGI.RIGHT_TEXT=2;
  BGI.BOTTOM_TEXT=0; BGI.TOP_TEXT=2; // or is it?? top=0 bottom=1 baseline=2

  // BGI colors
  BGI.BLACK=0; BGI.BLUE=1; BGI.GREEN=2; BGI.CYAN=3; BGI.RED=4; BGI.MAGENTA=5;
  BGI.BROWN=6; BGI.LIGHTGRAY=7; BGI.DARKGRAY=8; BGI.LIGHTBLUE=9; BGI.LIGHTGREEN=10;
  BGI.LIGHTCYAN=11; BGI.LIGHTRED=12; BGI.LIGHTMAGENTA=13; BGI.YELLOW=14; BGI.WHITE=15;
  BGI.MAXCOLORS=15; BGI.BGI_COLORS=16; // do we need this?

  // line style, thickness, and drawing mode
  BGI.SOLID_LINE=0; BGI.DOTTED_LINE=1; BGI.CENTER_LINE=2;
  BGI.DASHED_LINE=3; BGI.USERBIT_LINE=4; // line_styles enum
  BGI.NORM_WIDTH=1; BGI.THICK_WIDTH=3;
  BGI.COPY_PUT=0; BGI.XOR_PUT=1; BGI.OR_PUT=2; BGI.AND_PUT=3; BGI.NOT_PUT=4; // putimage_ops enum

  // fill styles
  BGI.EMPTY_FILL=0; BGI.SOLID_FILL=1; BGI.LINE_FILL=2; BGI.LTSLASH_FILL=3;
  BGI.SLASH_FILL=4; BGI.BKSLASH_FILL=5; BGI.LTBKSLASH_FILL=6; BGI.HATCH_FILL=7;
  BGI.XHATCH_FILL=8; BGI.INTERLEAVE_FILL=9; BGI.WIDE_DOT_FILL=10; BGI.CLOSE_DOT_FILL=11;
  BGI.USER_FILL=12;

  // numbers
  BGI.PI_CONV = (3.1415926 / 180.0);


  // the following are not in BGI spec

  BGI.fontFileIds = {
    'TRIP.CHR': BGI.TRIPLEX_FONT,
    'LITT.CHR': BGI.SMALL_FONT,
    'SANS.CHR': BGI.SANSSERIF_FONT,
    'GOTH.CHR': BGI.GOTHIC_FONT,
    'SCRI.CHR': BGI.SCRIPT_FONT,
    'SIMP.CHR': BGI.SIMPLEX_FONT,
    'TSCR.CHR': BGI.TRIPLEX_SCR_FONT,
    'LCOM.CHR': BGI.COMPLEX_FONT,
    'EURO.CHR': BGI.EUROPEAN_FONT,
    'BOLD.CHR': BGI.BOLD_FONT
  };

  BGI.fontFileList = [
    '', 'TRIP.CHR', 'LITT.CHR', 'SANS.CHR', 'GOTH.CHR', 'SCRI.CHR',
    'SIMP.CHR', 'TSCR.CHR', 'LCOM.CHR', 'EURO.CHR', 'BOLD.CHR'
  ];

  // actual scale values to multiply by given size index.
  BGI.fontScales = [ 1, 0.6, 2/3, 0.75, 1, 4/3, 5/3, 2, 2.5, 3, 4 ];

  // used in textheight()
  BGI.VAR_HEIGHT=0; BGI.MAIN_HEIGHT=1; BGI.DROP_HEIGHT=2; BGI.FULL_HEIGHT=3; BGI.ABOVE_HEIGHT=4;

  // num pixels between top of cell and top of font when scale = 4.
  BGI.fontPadAbove = [ 0, 10, 3, 11, 11, 16, 14, 9, 13, 12, 19 ]; // font 0 to 10

  // ASCII chars that have descenders. 1 = char goes below baseline.
  BGI.charDescenders = [
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,1,0,0,1,0,0,0,0,0,1,1,0,0,0,0,0,0,0,1,0,0,0,0,0,0,
    1,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,1,
    0,0,0,0,0,0,0,0,0,0,0,1,1,0,0,0,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,
    1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,
    0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0
  ];

  // windows mouse events
  BGI.WM_MOUSEMOVE     = { type:'mousemove', button:-1}; // called whenever the mouse moves
  BGI.WM_LBUTTONDBLCLK = { type:'dblclick',  button:0 }; // left mouse button is double clicked
  BGI.WM_LBUTTONDOWN   = { type:'mousedown', button:0 }; // left mouse button is clicked down
  BGI.WM_LBUTTONUP     = { type:'mouseup',   button:0 }; // left mouse button is released up
  BGI.WM_MBUTTONDBLCLK = { type:'dblclick',  button:1 }; // middle mouse button is double clicked
  BGI.WM_MBUTTONDOWN   = { type:'mousedown', button:1 }; // middle mouse button is clicked down
  BGI.WM_MBUTTONUP     = { type:'mouseup',   button:1 }; // middle mouse button is released up
  BGI.WM_RBUTTONDBLCLK = { type:'dblclick',  button:2 }; // right mouse button is double clicked
  BGI.WM_RBUTTONDOWN   = { type:'mousedown', button:2 }; // right mouse button is clicked down
  BGI.WM_RBUTTONUP     = { type:'mouseup',   button:2 }; // right mouse button is released up


////////////////////////////////////////////////////////////////////////////////

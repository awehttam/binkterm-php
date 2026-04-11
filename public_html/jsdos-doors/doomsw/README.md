# Doom JS-DOS Door Example

This directory contains an example **JS-DOS Door** for the 3D game **Doom**.

It is configured for the **shareware** release of Doom, using `DOOM1.WAD`.

## Setup

The sysop must download the Doom shareware files and unpack the game files directly into:

`public_html/jsdos-doors/doomsw/assets/`

Do not place the files in a nested subdirectory under `assets/`; the manifest expects the game files to exist directly in that folder.

For normal play, the assets directory should contain at least:

- `DOOM.EXE`
- `DOOM1.WAD`
- `SETUP.EXE`

On first install, **Admin Setup** can start with only `SETUP.EXE` present. The manifest marks `DOOM.EXE` and `DOOM1.WAD` as optional for config mode so you can generate `DEFAULT.CFG` before the full gameplay asset set is in place. Doom will create `DEFAULT.CFG`, and that generated file is then saved as the shared default for future play sessions. A prebuilt `DEFAULT.CFG` is not required.

## Download

The Doom shareware version can be downloaded from:

<https://archive.org/details/DoomsharewareEpisode>

-- Migration: 20260507170000 - Add networks table
-- Created: 2026-05-07 17:00:00 UTC

CREATE TABLE IF NOT EXISTS networks (
    id                   SERIAL PRIMARY KEY,
    domain               VARCHAR(50)  NOT NULL UNIQUE,
    name                 VARCHAR(100) NOT NULL,
    description          TEXT,
    website              VARCHAR(255),
    network_type         INTEGER      NOT NULL DEFAULT 1,
    allow_markup         BOOLEAN      NOT NULL DEFAULT FALSE,
    allow_media          BOOLEAN      NOT NULL DEFAULT FALSE,
    default_charset      VARCHAR(20),
    posting_name_policy  VARCHAR(20)  NOT NULL DEFAULT 'real_name',
    is_builtin           BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at           TIMESTAMP    NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'),
    updated_at           TIMESTAMP    NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'),
    CONSTRAINT networks_posting_name_policy_check CHECK (posting_name_policy IN ('real_name', 'username'))
);

-- Seed built-in network rows from the active FTN network list maintained at:
-- https://docs.google.com/spreadsheets/d/17pmf7cS9ocU99Rm6qlJD_OncqmbDI5Qj8Yw99A5bgVc/edit?gid=0#gid=0
-- The sheet has separate active rows for FidoNet zones 1-4; those are collapsed
-- into the single conventional "fidonet" domain used by BinktermPHP.
INSERT INTO networks (domain, name, description, website, network_type, allow_markup, allow_media, default_charset, posting_name_policy, is_builtin)
VALUES
    ('agoranet', 'AgoraNet', 'AgoraNet FTN network.', 'https://pharcyde.org', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('amiganet', 'AmigaNet', 'FTN network supporting the Amiga computer platform.', 'https://amiganet.vlzn.nl', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('araknet', 'ArakNet', 'ArakNet FTN network.', 'https://www.araknet.net', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('bbsnet', 'BBSNet', 'Small German and English FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('christnet', 'ChristNet', 'Christian FTN network with devotions, bible verses, prophecy, and poetry.', 'http://vintagebbsing.com/C146INFO.ZIP', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('commodorenet', 'CommodoreNet', 'Commodore-related FTN network.', 'http://104.171.245.227:8090/files/', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('cybernet', 'Cyber-Net', 'Cyber-Net FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('developernet', 'DeveloperNet', 'Programming and software development FTN network.', 'ftp://freeway.vkradio.com/developernet/INFO/DEVNET.ZIP', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('dixienet', 'DixieNet', 'DixieNet FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('dovenet', 'DoveNet', 'DoveNet FTN network.', 'https://dovenet.org', 1, FALSE, FALSE, 'CP437', 'username', TRUE),
    ('dreadnet', 'DreadNet', 'Private invite-only FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('famnet', 'famnet', 'Family and general-interest FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('fidonet', 'FidoNet', 'FidoNet FTN network. ', 'https://www.fidonet.org', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('fishingnet', 'FishingNet', 'Christian FTN network covering apologetics, counselling, charities, education, ministry, persecution, and prophecy.', 'https://erb.pw/fishnet.zip', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('fsxnet', 'fsxNet', 'fsxNet FTN network.', 'https://www.fsxnet.nz', 1, FALSE, FALSE, 'CP437', 'username', TRUE),
    ('funet', 'FuNet', 'Alternative FTN-compatible network.', 'https://www.fu-net.org', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('gamenet', 'GameNet', 'Gaming-focused FTN network.', 'ftp://gamenet.synchronetbbs.org/gamenet.zip', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('hobbynet', 'HobbyNet', 'Hobby-related FTN network.', 'https://hobbynet.hobbyline.com/docs/HOBBYNET.zip', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('ilink', 'iLink', 'General-interest FTN network.', 'https://www.techware2k.com/public/ilink/index.html', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('lovlynet', 'LovlyNet', 'LovlyNet FTN network for BinktermPHP-powered systems and systems that support UTF-8, Markdown, and experimental features.', 'https://www.lovelybits.org/lovlynet', 1, TRUE, TRUE, 'UTF-8', 'username', TRUE),
    ('metronet', 'MetroNet', 'General technology and Renegade BBS support network.', 'https://www.rgbbs.info', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('micronet', 'Micronet Information Network', 'General-interest FTN network.', 'https://www.minftn.net/MININFO.ZIP', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('mixtrnet', 'mixtrnet', 'Mixed-connectivity FTN network beyond the public internet.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('nexusnet', 'NexusNet', 'NexusNet FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('nixnet', 'NixNet', 'Unix, Linux, and BSD FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('pinet', 'piNET', 'Raspberry Pi and ARM SoC FTN network.', 'http://104.171.245.227:8090/files/', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('radioweathernet', 'Radio / Weather Net', 'Amateur radio and weather information FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('retronet', 'RetroNet', 'Retro gaming and computing FTN network.', 'http://104.171.245.227:8090/files/', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('scinet', 'SciNet', 'SciNet FTN network.', 'https://scinet-ftn.org/', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('spooknet', 'SpookNet', 'Conspiracies, government, crypto, security, and related discussion network.', 'https://bbsday.org/spooknet.7z', 1, FALSE, FALSE, 'CP437', 'username', TRUE),
    ('survivalnet', 'Survival Net', 'Survival Net FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('tormoznet', 'tormoznet', 'tormoznet FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('tqwnet', 'tqwNet', 'Coding, networking, and cyber essentials FTN network.', 'https://erb.pw/tqwinfo.zip', 1, FALSE, FALSE, 'CP437', 'username', TRUE),
    ('transnet', 'TransNet', 'Neutral data transportation network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('videotexnet', 'VideoTex Net', 'VideoTex Net FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('vkradio', 'VKRadio', 'Hobbyist radio, amateur radio, SWL, scanners, and SDR FTN network.', 'ftp://freeway.vkradio.com/vkradio.files/VK_INFO/VKRADIO.ZIP', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('weednet', 'WeedNet', 'FTN network for friends and fans of cannabis.', 'https://weednet.baybuds.online/index.php?option=com_jdownloads&view=category&catid=10&Itemid=266', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('whispernet', 'WhisperNet', 'General-interest FTN network.', 'http://www.filegate.net/oddball/infopack/whispnet.zip', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('winsnet', 'WINSnet', 'Wildcat!/WINServer sysop support network.', 'http://www.winsnet.info', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('wwivnet', 'WWIVnet', 'Gated WWIVnet FTN network.', 'https://www.wwivbbs.org/docs/network/wwivnet.html', 1, FALSE, FALSE, 'UTF-8', 'real_name', TRUE),
    ('zenet', 'ZENet', 'Herbs, healing, alternative medicine, and general chat network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('zeronet', 'ZeroNet', 'Cyber culture and BBS scene FTN network.', NULL, 1, FALSE, FALSE, 'CP437', 'real_name', TRUE),
    ('zudakanet', 'Zudaka Net', 'Experimental FTN network for Latin America.', 'https://bbs.docksud.com.ar/files/Zudaka/Zudaka.Info.Pack/zudaka.zip', 1, FALSE, FALSE, 'CP437', 'real_name', TRUE)
ON CONFLICT (domain) DO UPDATE SET
    name = EXCLUDED.name,
    description = COALESCE(networks.description, EXCLUDED.description),
    website = COALESCE(networks.website, EXCLUDED.website),
    network_type = COALESCE(networks.network_type, EXCLUDED.network_type),
    is_builtin = TRUE,
    updated_at = NOW() AT TIME ZONE 'UTC';

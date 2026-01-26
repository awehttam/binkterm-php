/**
 * Wordle Word Lists
 *
 * ANSWERS: Words that can be the daily answer (common, well-known words)
 * VALID_GUESSES: Additional words that are valid guesses but won't be answers
 */

const ANSWERS = [
    "about", "above", "abuse", "actor", "acute", "admit", "adopt", "adult", "after", "again",
    "agent", "agree", "ahead", "alarm", "album", "alert", "alike", "alive", "allow", "alone",
    "along", "alter", "among", "anger", "angle", "angry", "apart", "apple", "apply", "arena",
    "argue", "arise", "array", "aside", "asset", "avoid", "award", "aware", "badly", "baker",
    "bases", "basic", "basis", "beach", "began", "begin", "begun", "being", "belly", "below",
    "bench", "billy", "birth", "black", "blame", "blank", "blast", "bleed", "blend", "bless",
    "blind", "block", "blood", "blown", "board", "boost", "booth", "bound", "brain", "brand",
    "brave", "bread", "break", "breed", "brick", "bride", "brief", "bring", "broad", "broke",
    "brown", "brush", "build", "built", "bunch", "burst", "buyer", "cable", "calif", "carry",
    "catch", "cause", "chain", "chair", "chart", "chase", "cheap", "check", "chest", "chief",
    "child", "china", "chose", "civil", "claim", "class", "clean", "clear", "click", "climb",
    "clock", "close", "cloth", "cloud", "coach", "coast", "could", "count", "court", "cover",
    "craft", "crash", "crazy", "cream", "crime", "cross", "crowd", "crown", "curve", "cycle",
    "daily", "dance", "dated", "dealt", "death", "debut", "delay", "depth", "doing", "doubt",
    "dozen", "draft", "drain", "drama", "drank", "drawn", "dream", "dress", "drink", "drive",
    "drove", "dying", "eager", "early", "earth", "eight", "elite", "empty", "enemy", "enjoy",
    "enter", "entry", "equal", "error", "event", "every", "exact", "exist", "extra", "faith",
    "false", "fancy", "fatal", "fault", "favor", "feast", "field", "fifth", "fifty", "fight",
    "final", "first", "fixed", "flash", "fleet", "flesh", "float", "flood", "floor", "fluid",
    "focus", "force", "forth", "forty", "forum", "found", "frame", "frank", "fraud", "fresh",
    "front", "fruit", "fully", "funny", "ghost", "giant", "given", "glass", "globe", "glory",
    "going", "grace", "grade", "grain", "grand", "grant", "grass", "grave", "great", "green",
    "gross", "group", "grown", "guard", "guess", "guest", "guide", "happy", "harry", "heart",
    "heavy", "hence", "henry", "horse", "hotel", "house", "human", "ideal", "image", "index",
    "inner", "input", "issue", "japan", "jimmy", "joint", "jones", "judge", "juice", "knife",
    "knock", "known", "label", "large", "laser", "later", "laugh", "layer", "learn", "lease",
    "least", "leave", "legal", "level", "lewis", "light", "limit", "links", "lives", "local",
    "logic", "loose", "lower", "lucky", "lunch", "lying", "magic", "major", "maker", "march",
    "maria", "match", "maybe", "mayor", "meant", "media", "metal", "might", "minor", "minus",
    "mixed", "model", "money", "month", "moral", "motor", "mount", "mouse", "mouth", "movie",
    "music", "needs", "nerve", "never", "newly", "night", "noise", "north", "noted", "novel",
    "nurse", "occur", "ocean", "offer", "often", "order", "other", "ought", "outer", "owned",
    "owner", "oxide", "paint", "panel", "paper", "party", "peace", "peter", "phase", "phone",
    "photo", "piece", "pilot", "pitch", "place", "plain", "plane", "plant", "plate", "plaza",
    "point", "pound", "power", "press", "price", "pride", "prime", "print", "prior", "prize",
    "proof", "proud", "prove", "queen", "quick", "quiet", "quite", "radio", "raise", "range",
    "rapid", "ratio", "reach", "ready", "realm", "rebel", "refer", "reign", "relax", "reply",
    "right", "rival", "river", "robot", "rocky", "roman", "rough", "round", "route", "royal",
    "rural", "scale", "scene", "scope", "score", "sense", "serve", "seven", "shade", "shake",
    "shall", "shape", "share", "sharp", "sheet", "shelf", "shell", "shift", "shine", "shirt",
    "shock", "shoot", "short", "shown", "sight", "simon", "sixth", "sixty", "sized", "skill",
    "slave", "sleep", "slide", "slope", "small", "smart", "smell", "smile", "smith", "smoke",
    "snake", "solid", "solve", "sorry", "sound", "south", "space", "spare", "speak", "speed",
    "spend", "spent", "split", "spoke", "sport", "spot", "spray", "spread", "spring", "square",
    "stack", "staff", "stage", "stake", "stand", "start", "state", "steam", "steel", "steep",
    "stick", "still", "stock", "stone", "stood", "store", "storm", "story", "strip", "stuck",
    "study", "stuff", "style", "sugar", "suite", "super", "sweet", "swing", "table", "taken",
    "taste", "taxes", "teach", "teeth", "terry", "texas", "thank", "theft", "their", "theme",
    "there", "these", "thick", "thing", "think", "third", "those", "three", "threw", "throw",
    "tight", "times", "tired", "title", "today", "token", "total", "touch", "tough", "tower",
    "track", "trade", "train", "trash", "treat", "trend", "trial", "tribe", "trick", "tried",
    "troop", "truck", "truly", "trust", "truth", "twice", "under", "undue", "union", "unity",
    "until", "upper", "upset", "urban", "usage", "usual", "valid", "value", "video", "virus",
    "visit", "vital", "voice", "waste", "watch", "water", "wheel", "where", "which", "while",
    "white", "whole", "whose", "woman", "world", "worry", "worse", "worst", "worth", "would",
    "wound", "write", "wrong", "wrote", "yield", "young", "youth", "zebra", "zones"
];

// Additional valid guesses (not used as answers but can be guessed)
const VALID_GUESSES = [
    "aahed", "aalii", "aargh", "abaca", "abaci", "aback", "abaft", "abamp", "abase", "abash",
    "abate", "abaya", "abbey", "abbot", "abeam", "abele", "abets", "abhor", "abide", "abled",
    "abler", "ables", "abmho", "abode", "abohm", "aboil", "aboma", "aboon", "abort", "about",
    "above", "abris", "abuse", "abuts", "abysm", "abyss", "acari", "acerb", "aceta", "ached",
    "aches", "achoo", "acids", "acidy", "acing", "acini", "ackee", "acmes", "acmic", "acned",
    "acnes", "acock", "acold", "acorn", "acres", "acrid", "acted", "actin", "actor", "acute",
    "acyls", "adage", "adapt", "addax", "added", "adder", "adeem", "adept", "adieu", "adios",
    "adipic", "adobe", "adobo", "adopt", "adore", "adorn", "adown", "adult", "adunc", "adust",
    "adyta", "adzed", "adzes", "aecia", "aegis", "aeons", "aerie", "afara", "afars", "affix",
    "afire", "afoot", "afore", "afoul", "afrit", "after", "again", "agama", "agape", "agars"
];

// Combine all valid words for checking guesses
const ALL_WORDS = new Set([...ANSWERS, ...VALID_GUESSES]);

/**
 * Get the word for a specific day
 * Uses a seeded approach based on date to ensure consistency
 * @param {Date} date - The date to get the word for
 * @returns {string} The word of the day
 */
function getWordOfDay(date = new Date()) {
    // Use epoch day as seed for consistent daily word
    const epochMs = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
    const epochDay = Math.floor(epochMs / 86400000);
    const index = epochDay % ANSWERS.length;
    return ANSWERS[index].toUpperCase();
}

/**
 * Check if a word is valid (can be guessed)
 * @param {string} word - The word to check
 * @returns {boolean} Whether the word is valid
 */
function isValidWord(word) {
    return ALL_WORDS.has(word.toLowerCase());
}

/**
 * Get the puzzle number for a date
 * @param {Date} date - The date
 * @returns {number} Puzzle number
 */
function getPuzzleNumber(date = new Date()) {
    // Start from a reference date (Jan 1, 2024)
    const startDate = new Date(2024, 0, 1);
    const diffTime = date - startDate;
    const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
    return diffDays + 1;
}

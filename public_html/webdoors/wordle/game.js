/**
 * Wordle WebDoor Game
 */

class WordleGame {
    constructor() {
        this.webdoor = new WebDoor({ gameId: 'wordle' });
        this.wordOfDay = getWordOfDay();
        this.puzzleNumber = getPuzzleNumber();
        this.currentRow = 0;
        this.currentTile = 0;
        this.gameOver = false;
        this.won = false;
        this.guesses = [];
        this.keyStates = {};

        // Stats
        this.stats = {
            played: 0,
            won: 0,
            streak: 0,
            maxStreak: 0,
            distribution: [0, 0, 0, 0, 0, 0]  // Wins in 1-6 guesses
        };

        this.init();
    }

    async init() {
        // Initialize WebDoor session
        const session = await this.webdoor.init();
        this.updatePlayerName(session.user.display_name);

        // Load saved game state
        await this.loadGameState();

        // Build UI
        this.buildBoard();
        this.buildKeyboard();
        this.bindEvents();

        // Restore previous guesses if any
        this.restoreGuesses();

        // Show stats button
        document.getElementById('stats-btn').addEventListener('click', () => this.showStats());

        // New game button
        document.getElementById('new-game-btn').addEventListener('click', () => this.startNewGame());
    }

    startNewGame() {
        // Pick a random word for practice mode
        const randomIndex = Math.floor(Math.random() * ANSWERS.length);
        this.wordOfDay = ANSWERS[randomIndex].toUpperCase();
        this.puzzleNumber = -1;  // Indicates practice mode

        // Reset game state
        this.currentRow = 0;
        this.currentTile = 0;
        this.gameOver = false;
        this.won = false;
        this.guesses = [];
        this.keyStates = {};

        // Rebuild UI
        this.buildBoard();
        this.buildKeyboard();

        // Hide modal
        document.getElementById('stats-modal').classList.add('hidden');

        // Clear message
        this.showMessage('Practice Mode', '');
        setTimeout(() => this.showMessage('', ''), 2000);
    }

    updatePlayerName(name) {
        document.getElementById('player-name').textContent = name;
    }

    async loadGameState() {
        const saveData = await this.webdoor.load(0);
        if (saveData && saveData.data) {
            const data = saveData.data;

            // Check if save is for today's puzzle
            if (data.puzzleNumber === this.puzzleNumber) {
                this.guesses = data.guesses || [];
                this.currentRow = this.guesses.length;
                this.gameOver = data.gameOver || false;
                this.won = data.won || false;
                this.keyStates = data.keyStates || {};
            }

            // Load stats (persists across days)
            if (data.stats) {
                this.stats = data.stats;
            }
        }
    }

    async saveGameState() {
        const data = {
            puzzleNumber: this.puzzleNumber,
            guesses: this.guesses,
            gameOver: this.gameOver,
            won: this.won,
            keyStates: this.keyStates,
            stats: this.stats
        };

        await this.webdoor.save(0, data, {
            save_name: `Puzzle #${this.puzzleNumber}`,
            completed: this.gameOver
        });
    }

    buildBoard() {
        const board = document.getElementById('board');
        board.innerHTML = '';

        for (let i = 0; i < 6; i++) {
            const row = document.createElement('div');
            row.className = 'row';
            row.dataset.row = i;

            for (let j = 0; j < 5; j++) {
                const tile = document.createElement('div');
                tile.className = 'tile';
                tile.dataset.row = i;
                tile.dataset.col = j;
                row.appendChild(tile);
            }

            board.appendChild(row);
        }
    }

    buildKeyboard() {
        const keyboard = document.getElementById('keyboard');
        keyboard.innerHTML = '';

        const rows = [
            ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
            ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L'],
            ['ENTER', 'Z', 'X', 'C', 'V', 'B', 'N', 'M', '⌫']
        ];

        rows.forEach(row => {
            const rowEl = document.createElement('div');
            rowEl.className = 'keyboard-row';

            row.forEach(key => {
                const keyEl = document.createElement('button');
                keyEl.className = 'key';
                keyEl.textContent = key;
                keyEl.dataset.key = key;

                if (key === 'ENTER' || key === '⌫') {
                    keyEl.classList.add('wide');
                }

                keyEl.addEventListener('click', () => this.handleKey(key));
                rowEl.appendChild(keyEl);
            });

            keyboard.appendChild(rowEl);
        });
    }

    bindEvents() {
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey || e.altKey) return;

            if (e.key === 'Enter') {
                this.handleKey('ENTER');
            } else if (e.key === 'Backspace') {
                this.handleKey('⌫');
            } else if (/^[a-zA-Z]$/.test(e.key)) {
                this.handleKey(e.key.toUpperCase());
            }
        });

        // Modal close buttons
        document.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.closest('.modal').classList.add('hidden');
            });
        });

        // Close modal on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.add('hidden');
                }
            });
        });
    }

    handleKey(key) {
        if (this.gameOver) return;

        if (key === 'ENTER') {
            this.submitGuess();
        } else if (key === '⌫') {
            this.deleteLetter();
        } else if (/^[A-Z]$/.test(key)) {
            this.addLetter(key);
        }
    }

    addLetter(letter) {
        if (this.currentTile >= 5) return;

        const tile = this.getTile(this.currentRow, this.currentTile);
        tile.textContent = letter;
        tile.classList.add('filled');
        this.currentTile++;
    }

    deleteLetter() {
        if (this.currentTile <= 0) return;

        this.currentTile--;
        const tile = this.getTile(this.currentRow, this.currentTile);
        tile.textContent = '';
        tile.classList.remove('filled');
    }

    async submitGuess() {
        if (this.currentTile !== 5) {
            this.showMessage('Not enough letters', 'error');
            this.shakeRow(this.currentRow);
            return;
        }

        const guess = this.getCurrentGuess();

        if (!isValidWord(guess)) {
            this.showMessage('Not in word list', 'error');
            this.shakeRow(this.currentRow);
            return;
        }

        this.guesses.push(guess);

        // Evaluate the guess
        const result = this.evaluateGuess(guess);

        // Reveal tiles with animation
        await this.revealRow(this.currentRow, result);

        // Update keyboard colors
        this.updateKeyboard(guess, result);

        // Check win/lose
        if (guess === this.wordOfDay) {
            this.gameOver = true;
            this.won = true;
            this.stats.played++;
            this.stats.won++;
            this.stats.streak++;
            this.stats.maxStreak = Math.max(this.stats.maxStreak, this.stats.streak);
            this.stats.distribution[this.currentRow]++;

            await this.saveGameState();

            // Submit score to leaderboard (lower is better - fewer guesses)
            const score = 7 - (this.currentRow + 1);  // 6 for 1 guess, 1 for 6 guesses
            await this.webdoor.submitScore('daily', score, {
                puzzle: this.puzzleNumber,
                guesses: this.currentRow + 1
            });

            setTimeout(() => {
                this.bounceRow(this.currentRow);
                this.showMessage(this.getWinMessage(), 'success');
                setTimeout(() => this.showStats(), 1500);
            }, 1800);

        } else if (this.currentRow >= 5) {
            this.gameOver = true;
            this.won = false;
            this.stats.played++;
            this.stats.streak = 0;

            await this.saveGameState();

            setTimeout(() => {
                this.showMessage(this.wordOfDay, 'error');
                setTimeout(() => this.showStats(), 1500);
            }, 1800);

        } else {
            this.currentRow++;
            this.currentTile = 0;
            await this.saveGameState();
        }
    }

    getCurrentGuess() {
        let guess = '';
        for (let i = 0; i < 5; i++) {
            const tile = this.getTile(this.currentRow, i);
            guess += tile.textContent;
        }
        return guess;
    }

    evaluateGuess(guess) {
        const result = ['absent', 'absent', 'absent', 'absent', 'absent'];
        const wordArray = this.wordOfDay.split('');
        const guessArray = guess.split('');
        const used = [false, false, false, false, false];

        // First pass: mark correct letters
        for (let i = 0; i < 5; i++) {
            if (guessArray[i] === wordArray[i]) {
                result[i] = 'correct';
                used[i] = true;
            }
        }

        // Second pass: mark present letters
        for (let i = 0; i < 5; i++) {
            if (result[i] === 'correct') continue;

            for (let j = 0; j < 5; j++) {
                if (!used[j] && guessArray[i] === wordArray[j]) {
                    result[i] = 'present';
                    used[j] = true;
                    break;
                }
            }
        }

        return result;
    }

    async revealRow(rowIndex, result) {
        const row = document.querySelector(`.row[data-row="${rowIndex}"]`);
        const tiles = row.querySelectorAll('.tile');

        for (let i = 0; i < 5; i++) {
            await this.delay(300);
            tiles[i].classList.add('reveal', result[i]);
        }

        await this.delay(300);
    }

    updateKeyboard(guess, result) {
        for (let i = 0; i < 5; i++) {
            const letter = guess[i];
            const state = result[i];

            // Only upgrade key state (absent < present < correct)
            const currentState = this.keyStates[letter];
            if (!currentState ||
                (currentState === 'absent' && state !== 'absent') ||
                (currentState === 'present' && state === 'correct')) {
                this.keyStates[letter] = state;
            }

            const keyEl = document.querySelector(`.key[data-key="${letter}"]`);
            if (keyEl) {
                keyEl.classList.remove('correct', 'present', 'absent');
                keyEl.classList.add(this.keyStates[letter]);
            }
        }
    }

    restoreGuesses() {
        for (let i = 0; i < this.guesses.length; i++) {
            const guess = this.guesses[i];
            const result = this.evaluateGuess(guess);

            // Set tiles
            for (let j = 0; j < 5; j++) {
                const tile = this.getTile(i, j);
                tile.textContent = guess[j];
                tile.classList.add('filled', result[j]);
            }

            // Update keyboard
            this.updateKeyboard(guess, result);
        }

        // Set current position
        if (this.gameOver) {
            if (this.won) {
                this.showMessage(this.getWinMessage(), 'success');
            } else {
                this.showMessage(this.wordOfDay, 'error');
            }
        }
    }

    getTile(row, col) {
        return document.querySelector(`.tile[data-row="${row}"][data-col="${col}"]`);
    }

    shakeRow(rowIndex) {
        const row = document.querySelector(`.row[data-row="${rowIndex}"]`);
        row.classList.add('shake');
        setTimeout(() => row.classList.remove('shake'), 500);
    }

    bounceRow(rowIndex) {
        const row = document.querySelector(`.row[data-row="${rowIndex}"]`);
        const tiles = row.querySelectorAll('.tile');
        tiles.forEach((tile, i) => {
            setTimeout(() => tile.classList.add('bounce'), i * 100);
        });
    }

    showMessage(message, type = '') {
        const bar = document.getElementById('message-bar');
        bar.textContent = message;
        bar.className = type;

        if (type) {
            setTimeout(() => {
                bar.textContent = '';
                bar.className = '';
            }, 3000);
        }
    }

    getWinMessage() {
        const messages = [
            'Genius!',
            'Magnificent!',
            'Impressive!',
            'Splendid!',
            'Great!',
            'Phew!'
        ];
        return messages[this.currentRow] || 'You won!';
    }

    async showStats() {
        const modal = document.getElementById('stats-modal');

        // Update stats display
        document.getElementById('stat-played').textContent = this.stats.played;
        document.getElementById('stat-win-pct').textContent =
            this.stats.played > 0 ? Math.round((this.stats.won / this.stats.played) * 100) : 0;
        document.getElementById('stat-streak').textContent = this.stats.streak;
        document.getElementById('stat-max-streak').textContent = this.stats.maxStreak;

        // Build distribution chart
        const distContainer = document.getElementById('guess-distribution');
        distContainer.innerHTML = '';
        const maxDist = Math.max(...this.stats.distribution, 1);

        for (let i = 0; i < 6; i++) {
            const row = document.createElement('div');
            row.className = 'distribution-row';

            const label = document.createElement('span');
            label.textContent = i + 1;

            const bar = document.createElement('div');
            bar.className = 'distribution-bar';
            const width = (this.stats.distribution[i] / maxDist) * 100;
            bar.style.width = `${Math.max(width, 7)}%`;
            bar.textContent = this.stats.distribution[i];

            if (this.gameOver && this.won && i === this.guesses.length - 1) {
                bar.classList.add('highlight');
            }

            row.appendChild(label);
            row.appendChild(bar);
            distContainer.appendChild(row);
        }

        // Load leaderboard
        await this.loadLeaderboard();

        // Show/hide new game button
        const actionsDiv = document.getElementById('game-over-actions');
        if (this.gameOver) {
            actionsDiv.classList.remove('hidden');
        } else {
            actionsDiv.classList.add('hidden');
        }

        modal.classList.remove('hidden');
    }

    async loadLeaderboard() {
        const container = document.getElementById('leaderboard-list');
        container.innerHTML = '<div>Loading...</div>';

        try {
            const data = await this.webdoor.getLeaderboard('daily', { limit: 10, scope: 'today' });

            if (data.entries.length === 0) {
                container.innerHTML = '<div>No scores yet today</div>';
                return;
            }

            container.innerHTML = '';
            data.entries.forEach((entry, index) => {
                const row = document.createElement('div');
                row.className = 'leaderboard-entry';

                if (data.user_entry && entry.display_name === data.user_entry.display_name) {
                    row.classList.add('current-user');
                }

                row.innerHTML = `
                    <span class="rank">#${index + 1}</span>
                    <span class="name">${entry.display_name}</span>
                    <span class="score">${7 - entry.score} guesses</span>
                `;
                container.appendChild(row);
            });
        } catch (error) {
            container.innerHTML = '<div>Could not load leaderboard</div>';
        }
    }

    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Start the game when page loads
document.addEventListener('DOMContentLoaded', () => {
    window.game = new WordleGame();
});

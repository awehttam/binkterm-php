<?php

namespace BinktermPHP\TelnetServer;

/**
 * UI intent contract for terminal-side feature handlers.
 *
 * Handlers target these common interaction patterns instead of binding
 * directly to full-screen widgets or bespoke prompt loops.
 */
interface TerminalShellInterface
{
    /**
     * Present a selectable list and return the chosen item index.
     *
     * @param resource $conn
     * @param array $state
     * @param string[] $items
     * @param array{
     *   prompt?:string,
     *   empty_message?:string,
     *   status_bar?:array<int,array{text:string,color:string}>,
     *   selected_index?:int
     * } $options
     */
    public function chooseFromList($conn, array &$state, string $title, array $items, array $options = []): ?int;

    /**
     * Prompt for free-form text input.
     *
     * @param resource $conn
     * @param array $state
     * @param array{
     *   prefill?:string,
     *   max_length?:int,
     *   redraw_fn?:callable,
     *   inline_prompt?:bool,
     *   footer_hint?:string
     * } $options
     */
    public function promptText($conn, array &$state, string $title, string $prompt, array $options = []): ?string;

    /**
     * Prompt for a single-key action choice in a framed/modal style.
     *
     * @param resource $conn
     * @param array $state
     * @param string[] $allowedKeys Lowercase single-character action keys
     * @param array{
     *   redraw_fn?:callable,
     *   labels?:array<string,string>,
     *   default?:string
     * } $options
     */
    public function promptKey($conn, array &$state, string $title, string $prompt, array $allowedKeys, array $options = []): ?string;

    /**
     * Display a detail/read-only screen and wait for dismissal.
     *
     * @param resource $conn
     * @param array $state
     * @param string[] $lines
     * @param array{continue_prompt?:string} $options
     */
    public function showText($conn, array &$state, string $title, array $lines, array $options = []): void;

    /**
     * Render a non-blocking detail panel.
     *
     * @param resource $conn
     * @param array $state
     * @param string[] $lines
     * @param array{vertical_margin?:int, footer_lines?:int} $options
     */
    public function renderPanel($conn, array &$state, string $title, array $lines, array $options = []): void;

    /**
     * Display a scrollable panel and return an action key when the user exits.
     *
     * @param resource $conn
     * @param array $state
     * @param array{
     *   redraw_fn?:callable,
     *   initial_offset?:int,
     *   extra_keys?:array<string,string>,
     *   color_scheme?:array{border?:string,divider?:string,title_bar?:string,body?:string},
     *   status_segments?:array<int,array{text:string,color?:string}>,
     *   status_bar_bg?:string,
     *   status_bar_fill?:string,
     *   status_line?:string
     * } $options
     * @return string|null Action key such as 'quit' or a caller-defined extra key.
     */
    public function showScrollablePanel($conn, array &$state, string $title, array $lines, array $options = []): ?string;

    /**
     * Display a message viewer and return the viewer action.
     *
     * This wraps the shared message-reader behavior behind the shell contract
     * so handlers do not call the low-level helper directly.
     *
     * @param resource $conn
     * @param array $state
     * @param array $headerLines
     * @param array $wrappedLines
     * @param string $statusLine
     * @param int $rows
     * @param int $initialOffset
     * @param bool $allowDownloadAction
     * @param array $kludgeLines
     * @param callable|null $rebuildFn
     * @param array $imageRefs
     * @param callable|null $imageFn
     * @param array $extraKeys
     * @param array $helpItems
     * @param array $options
     * @return array{action:string,offset:int}
     */
    public function showMessageViewer(
        $conn,
        array &$state,
        array $headerLines,
        array $wrappedLines,
        string $statusLine,
        int $rows,
        int $initialOffset = 0,
        bool $allowDownloadAction = false,
        array $kludgeLines = [],
        ?callable $rebuildFn = null,
        array $imageRefs = [],
        ?callable $imageFn = null,
        array $extraKeys = [],
        array $helpItems = [],
        array $options = []
    ): array;

    /**
     * Display a message list and return the selected action.
     *
     * @param resource $conn
     * @param array $state
     * @param array $extraKeys
     * @param array $extraStatusSegments
     * @param array $options
     * @param array $helpItems
     * @return array{action:string,index:int,selectedIndex:int}
     */
    public function showMessageList(
        $conn,
        array &$state,
        string $title,
        array $messages,
        int $page,
        int $totalPages,
        int $selectedIndex,
        array $extraKeys = [],
        array $extraStatusSegments = [],
        array $options = [],
        array $helpItems = []
    ): array;

    /**
     * Display a generic selectable list and return the selected action.
     *
     * @param resource $conn
     * @param array $state
     * @param array $extraKeys
     * @param array $options
     * @param array $helpItems
     * @return array{action:string,index:int,selectedIndex:int}
     */
    public function showSelectableList(
        $conn,
        array &$state,
        string $title,
        array $rows,
        int $page,
        int $totalPages,
        int $selectedIndex,
        array $statusBar,
        array $extraKeys = [],
        ?callable $rebuildFn = null,
        array $options = [],
        array $helpItems = []
    ): array;

    /**
     * Display a short alert/notice and wait for dismissal.
     *
     * @param resource $conn
     * @param array $state
     */
    public function showAlert($conn, array &$state, string $title, string $message, string $style = 'info'): void;

    /**
     * Display a confirmation dialog and return the chosen key.
     *
     * @param resource $conn
     * @param array $state
     * @param array<string,string> $choices
     * @param array{redraw_fn?:callable} $options
     */
    public function showConfirmDialog($conn, array &$state, string $title, string $message, array $choices = ['y' => 'Confirm', 'n' => 'Cancel'], string $default = 'n', array $options = []): string;

    /**
     * Display a non-blocking working overlay.
     *
     * @param resource $conn
     * @param array $state
     * @param array{color_scheme?:array} $options
     */
    public function showWorkingOverlay($conn, array &$state, string $message, array $options = []): void;

    /**
     * Display a checkbox list dialog and return the selected action.
     *
     * @param resource $conn
     * @param array $state
     * @param array{color_scheme?:array} $options
     * @return array|null
     */
    public function showCheckboxListDialog(
        $conn,
        array &$state,
        callable $titleFn,
        array $items,
        array $selectedIndices = [],
        int $maxSelect = 0,
        string $atLimitMessage = '',
        string $hintConfirm = 'Done',
        string $hintSkip = 'Skip',
        ?callable $redrawFn = null,
        array $options = []
    ): ?array;

    /**
     * Display a selectable dialog and return the chosen item.
     *
     * @param resource $conn
     * @param array $state
     * @param array{redraw_fn?:callable} $options
     * @return array{action:string,index:int}|null
     */
    public function showSelectableDialog($conn, array &$state, string $title, array $items, string $hintSelect = 'Select', string $hintBack = 'Back', int $selectedIndex = 0, ?callable $redrawFn = null, array $options = []): ?array;

    /**
     * Run the address book / nodelist picker flow.
     *
     * Prompts for a search term, queries the address book and nodelist, and
     * presents a selectable result list. Returns ['name' => ..., 'address' => ...]
     * on selection or null on cancel/disconnect.
     *
     * @param resource $conn
     * @param array $state
     * @param string $apiBase
     * @param string $session
     * @return array{name:string,address:string}|null
     */
    public function showAddressPicker($conn, array &$state, string $apiBase, string $session): ?array;

    /**
     * Display a read-only public user profile viewer.
     *
     * @param resource $conn
     * @param array $state
     * @param array $profile
     */
    public function showPublicProfileViewer($conn, array &$state, array $profile, array $options = []): void;

    /**
     * Display a paged box and return any stop key pressed.
     *
     * @param resource $conn
     * @param array $state
     * @param array{color_scheme?:array,stop_keys?:array<int,string>} $options
     */
    public function showPagedBox($conn, array &$state, string $title, array $lines, string $continuePrompt, int $verticalMargin = 2, array $stopKeys = [], array $options = []): ?string;
}

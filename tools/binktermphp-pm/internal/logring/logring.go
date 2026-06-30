// Package logring provides a thread-safe fixed-capacity circular log buffer.
package logring

import "sync"

const DefaultCapacity = 500

type LogRing struct {
	mu    sync.RWMutex
	buf   []string
	cap   int
	start int
	count int
}

func New(cap int) *LogRing {
	if cap <= 0 {
		cap = DefaultCapacity
	}
	return &LogRing{buf: make([]string, cap), cap: cap}
}

// Append adds a line to the ring, evicting the oldest entry when full.
func (r *LogRing) Append(line string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.count < r.cap {
		r.buf[(r.start+r.count)%r.cap] = line
		r.count++
	} else {
		r.buf[r.start] = line
		r.start = (r.start + 1) % r.cap
	}
}

// Tail returns the last n lines in chronological order.
func (r *LogRing) Tail(n int) []string {
	r.mu.RLock()
	defer r.mu.RUnlock()
	if n > r.count {
		n = r.count
	}
	if n == 0 {
		return nil
	}
	out := make([]string, n)
	base := (r.start + r.count - n) % r.cap
	for i := 0; i < n; i++ {
		out[i] = r.buf[(base+i)%r.cap]
	}
	return out
}

// All returns all stored lines in chronological order.
func (r *LogRing) All() []string {
	return r.Tail(r.count)
}

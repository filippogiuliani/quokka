"""Quokka's notification sounds, made here from sine waves (no recordings, nothing to license).

    python3 sounds/make.py <dir>     → <dir>/<name>.wav
then each wav becomes static/sounds/<name>.mp3 and .ogg (see sounds/README.md).
"""
import math
import random
import struct
import sys
import wave

SR = 44100


def silence(sec):
    return [0.0] * int(SR * sec)


def tone(freq, sec, partials=((1, 1.0),), decay=6.0, attack=0.004, sweep=None):
    """A struck tone: harmonics (ratio, level), exponential decay; sweep=(to_freq, seconds) bends the pitch."""
    out = []
    phase = [0.0] * len(partials)
    for i in range(int(SR * sec)):
        t = i / SR
        f = freq
        if sweep:
            to, dur = sweep
            k = min(t / dur, 1.0)
            f = freq * (to / freq) ** k
        env = min(t / attack, 1.0) * math.exp(-decay * t)
        s = 0.0
        for j, (ratio, level) in enumerate(partials):
            phase[j] += 2 * math.pi * f * ratio / SR
            s += level * math.sin(phase[j]) * math.exp(-decay * (ratio - 1) * 0.35 * t)
        out.append(s * env)
    return out


def pluck(freq, sec, damp=0.996):
    """Karplus–Strong: a plucked string."""
    n = int(SR / freq)
    rnd = random.Random(int(freq))
    buf = [rnd.uniform(-1, 1) for _ in range(n)]
    out = []
    for i in range(int(SR * sec)):
        a = buf[i % n]
        b = buf[(i + 1) % n]
        buf[i % n] = damp * 0.5 * (a + b)
        out.append(a)
    return out


def mix(*parts):
    """[(start_seconds, samples), …] → one track."""
    length = max(int(SR * start) + len(s) for start, s in parts)
    out = [0.0] * length
    for start, s in parts:
        o = int(SR * start)
        for i, v in enumerate(s):
            out[o + i] += v
    return out


BELL = ((1, 1.0), (2.0, 0.35), (2.76, 0.3), (5.4, 0.12))
MARIMBA = ((1, 1.0), (4.0, 0.25), (9.2, 0.05))
GLASS = ((1, 1.0), (1.003, 0.8), (2.32, 0.2))

SOUNDS = {
    'bell': lambda: tone(1318.5, 1.6, BELL, decay=3.2),
    'doorbell': lambda: mix((0, tone(659.3, 1.2, BELL, decay=3.5)), (0.42, tone(523.3, 1.4, BELL, decay=3.0))),
    'two-notes': lambda: mix((0, tone(1046.5, 0.6, MARIMBA, decay=9)), (0.14, tone(1568.0, 0.8, MARIMBA, decay=7))),
    'three-notes': lambda: mix((0, tone(784.0, 0.5, MARIMBA, decay=10)), (0.11, tone(987.8, 0.5, MARIMBA, decay=10)),
                            (0.22, tone(1174.7, 0.8, MARIMBA, decay=7))),
    'drop': lambda: tone(700, 0.35, ((1, 1.0),), decay=14, sweep=(1500, 0.06)),
    'bubble': lambda: mix((0, tone(380, 0.18, ((1, 1.0),), decay=20, sweep=(950, 0.08))),
                         (0.12, tone(520, 0.2, ((1, 0.7),), decay=20, sweep=(1250, 0.08)))),
    'glass': lambda: tone(2093.0, 1.5, GLASS, decay=3.0),
    'harp': lambda: mix(*[(i * 0.09, pluck(f, 1.2)) for i, f in enumerate((523.3, 659.3, 784.0, 1046.5))]),
    'gong': lambda: tone(196.0, 2.2, ((1, 1.0), (1.48, 0.5), (2.1, 0.35), (2.9, 0.2)), decay=1.8, attack=0.02),
    'xylophone': lambda: mix((0, tone(1760, 0.3, ((1, 1.0), (3.9, 0.3)), decay=18)),
                            (0.1, tone(1760, 0.3, ((1, 1.0), (3.9, 0.3)), decay=18))),
}


def write(path, samples):
    samples = samples + silence(0.05)
    peak = max(abs(s) for s in samples) or 1.0
    gain = 0.7 / peak                                   # about -3 dB
    with wave.open(path, 'wb') as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(b''.join(struct.pack('<h', int(max(-1, min(1, s * gain)) * 32767)) for s in samples))


if __name__ == '__main__':
    out = sys.argv[1] if len(sys.argv) > 1 else '.'
    for name, make in SOUNDS.items():
        write(f'{out}/{name}.wav', make())
        print(name)

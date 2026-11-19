# Notification sounds

Quokka's sounds (bell, doorbell, two notes, three notes, drop, bubble, glass, harp, gong, xylophone) are made
by `make.py` from sine waves and a plucked-string model: no recordings, nothing to license. To change or add one, edit
`SOUNDS` in `make.py`, then:

    python3 sounds/make.py /tmp/s
    for f in /tmp/s/*.wav; do n=$(basename $f .wav)
      ffmpeg -y -i $f -codec:a libmp3lame -q:a 4 snappymail/v/0.0.0/static/sounds/$n.mp3
      ffmpeg -y -i $f -codec:a libvorbis -q:a 4 snappymail/v/0.0.0/static/sounds/$n.ogg
    done

Every `.mp3` in `static/sounds/` appears in Settings → General → Notifications → Sound; its name comes from the translations, `QUOKKA/SOUND_<FILE>` (the `.ogg` is for browsers
without mp3).

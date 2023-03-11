Dropseeker
---------
Transcribe podcasts to make them searchable, and then extract audio clips based on search terms.

I initially wrote this to make it easier to make drops for the Doughboys podcast.

To download and transcribe an entire podcast:

`php fetch.php --feed http://example.com/feed.xml`

To find all occurrences of someone saying "have a great day", extract the audio plus an additional second on each side, and save the clips to the directory `great-day/`,

`php search.php --search "have a great day" --extract --before 1 --after 1 --output_dir great-day/`

Requirements
------------
The transcription portion requires the audio transcription tool [whisper](https://github.com/openai/whisper). It is easy to install and free to use.

To extract clips, you'll need [ffmpeg](https://ffmpeg.org/download.html). It is also easy to install and free to use.

Default Options
---------------
If you want to specify a default set of command line options different from what Dropseeker specifies, you can do so by creating a file called `dropseeker.conf` in this directory. Add a line like this for each option you want to specify as a default:

```
key=value
```

or for options that don't take a value, just

```
key
```

For example, this is a valid `dropseeker.conf` file:

```
episode_dir=/path/to/episode/dir/
before=2
after=5
confirm
```

You can still specify different values for these options on the command line that will overwrite the values you listed in `dropseeker.conf`.

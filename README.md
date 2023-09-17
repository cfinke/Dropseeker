Dropseeker
---------
Transcribe podcasts to make them searchable, and then extract audio clips based on search terms.

I initially wrote this to make it easier to make drops for the Doughboys podcast.

Example Usage
-------------
To download and transcribe an entire podcast:

`php fetch.php --feed http://example.com/feed.xml`

To find all occurrences of someone saying "have a great day", extract the audio plus an additional second on each side, and save the clips to the directory `great-day/`,

`php search.php --search "have a great day" --extract --before 1 --after 1 --output_dir great-day/`

Requirements
------------
The transcription portion requires the audio transcription tool [whisper](https://github.com/openai/whisper). It is easy to install and free to use.

To extract clips, you'll need [ffmpeg](https://ffmpeg.org/download.html). It is also easy to install and free to use.

Optional Enhancements
---------------------
You can also use the [whisper.cpp](https://github.com/ggerganov/whisper.cpp) port of `whisper`. It is free to use, but not as easy to install. It is, however, much faster in certain environments than the original `whisper` command line tool.

If you have `whisper.cpp` installed, you can tell Dropseeker to use it by specifying the `--whisper_cpp /path/to/whisper.cpp/directory/` command line option.

All Command Line Options
------------------------

For `fetch.php`:

Required:

```
--feed [url]               The URL of a podcast RSS feed.
```

Optional:

```
--after_date [YYYY-MM-DD]  Only download/transcribe episodes published after this date.
--before_date [YYYY-MM-DD] Only download/transcribe episodes published before this date.
--confirm                  Require confirmation before downloading or transcribing an episode.
--episode_dir [path]       The directory in which to store the episode directories.
--exclude [string]         Don't download episodes that match this string.
--fetch_only               Just download episodes; don't transcribe.
--help                     Show the usage instructions.
--match [string]           Only download/transcribe episodes that match this string.
--title [string]           The string that should be used for the folders containing the recordings and transcripts.
--transcript_dir [path]    The directory in which to store the transcript directories.
--transcribe_only          Just transcribe episodes; don't download any new ones.
--whisper_cpp [path]       The path to whisper.cpp's installation folder, if you want to use it instead of the standard whisper tool. This folder should contain the `main` executable.
--whisper_[arg] [?arg]     Pass the command line option `arg` to the `whisper` executable. e.g., `--whisper_model medium` will call run `whisper --model medium`.

                           Default whisper args:
                                --model tiny
                                --output_dir The subdirectory of --transcript_dir with the same name as --title.
```

For `search.php`

Required:

```
--search [string]         What to search for in transcripts. Supports wildcards like 'foo*' (words that start
                          with foo), 'foo * bar' ('foo' and 'bar' separated by one word), or 'foo*baz*bar' (any
                          word starting with 'foo', containing 'baz', and ending with 'bar').
```

Optional:

```
--after [float]           Extract an additional __ seconds from after each match.
--before [float]          Extract an additional __ seconds from before each match.
--exclude [string]        A search string that, if it matches text around the search result, will be excluded from the final results.
--episode_dir [path]      The directory in which the episode directories are stored, if not in the default location.
--extract                 Extract audio clips of each match.
--help                    Show the usage instructions.
--context [string]        Only consider a match if the full prefix + match + suffix also includes this string.
--output_dir [path]       The directory in which to store the extracted audio clips.
--limit [int]             Stop searching entirely after finding this many total matches.
--limit_per_episode [int] Stop searching an episode after finding this many matches in it.
--match [string]          Only check episodes that include this string in their filename.
--podcast [string]        Only search transcripts from podcasts that include this string in their title.
--prefix_words [int]      Show this many words before the matching string in the text search results.
--suffix_words [int]      Show this many words after the matching string in the text search results.
--transcript_dir [path]   The directory in which the transcript directories are stored, if not in the default location.
```

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

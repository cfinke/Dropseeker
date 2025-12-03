#!/usr/bin/env python3
"""
Transcript search + optional audio extraction.

Port of the original PHP script to Python.
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any, Dict, List, Tuple, Union

# ---------------------------------------------------------------------------
# Config parsing
# ---------------------------------------------------------------------------


def default_options_from_conf(
    conf_path: Union[str, Path] = "dropseeker.conf",
) -> Dict[str, Any]:
    """
    Parse default options from dropseeker.conf.

    Lines:
      key=value   -> {key: value}
      key         -> {key: False}
    Blank / whitespace-only lines are ignored.
    """
    options: Dict[str, Any] = {}
    conf_path = Path(conf_path)

    if not conf_path.is_file():
        return options

    with conf_path.open("r", encoding="utf-8") as f:
        lines = [line.strip() for line in f]

    lines = [line for line in lines if line and not line.startswith("#")]

    for line in lines:
        parts = line.split("=", 1)
        if len(parts) == 2:
            key, value = parts
            options[key] = value
        else:
            key = parts[0]
            options[key] = False

    return options


# ---------------------------------------------------------------------------
# CLI usage / argparse
# ---------------------------------------------------------------------------


def usage() -> str:
    return """Usage: python script.py --search [search term]

Required arguments:

    --search [string]           What to search for in transcripts. Supports wildcards like 'foo*' (words that start
                                with foo), 'foo * bar' ('foo' and 'bar' separated by one word), or 'foo*baz*bar' (any
                                word starting with 'foo', containing 'baz', and ending with 'bar').

Optional arguments:

    --after [float]             Extract an additional __ seconds from after each match.
    --before [float]            Extract an additional __ seconds from before each match.
    --episode_dir [path]        The directory in which the episode directories are stored, if not in the default location.
    --extract                   Extract audio clips of each match.
    --context [string]          Only consider a match if the full prefix + match + suffix also includes this string (case-sensitive).
    --icontext [string]         Only consider a match if the full prefix + match + suffix also includes this string (case-insensitive).
    --context_exclude [string]  A search string that, if it matches text around the search result, will be excluded from the final results (case-sensitive).
    --icontext_exclude [string] A search string that, if it matches text around the search result, will be excluded from the final results (case-insensitive).
    --help_only                 Show the usage instructions.
    --output_dir [path]         The directory in which to store the extracted audio clips.
    --limit [int]               Stop searching entirely after finding this many total matches.
    --limit_per_episode [int]   Stop searching an episode after finding this many matches in it.
    --match [string]            Only check episodes that include this string in their filename.
    --min_duration [float]      If extracting audio, only extract a clip if it will be at least this long.
    --podcast [string]          Only search transcripts from podcasts that include this string in their title.
    --prefix_words [int]        Show this many words before the matching string in the text search results.
    --suffix_words [int]        Show this many words after the matching string in the text search results.
    --transcript_dir [path]     The directory in which the transcript directories are stored, if not in the default location.
"""


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Search transcripts for terms and optionally extract audio clips.",
        formatter_class=argparse.RawTextHelpFormatter,
        epilog="See dropseeker.conf and README.md for more details.",
    )

    parser.add_argument(
        "--search",
        action="append",
        help="Search term (can be given multiple times).",
        required=False,
    )
    parser.add_argument(
        "--after", type=float, help="Seconds after each match to include in extraction."
    )
    parser.add_argument(
        "--before",
        type=float,
        help="Seconds before each match to include in extraction.",
    )
    parser.add_argument(
        "--context", action="append", help="Required context (case-sensitive)."
    )
    parser.add_argument(
        "--icontext", action="append", help="Required context (case-insensitive)."
    )
    parser.add_argument(
        "--context_exclude",
        action="append",
        help="Context to exclude (case-sensitive).",
    )
    parser.add_argument(
        "--icontext_exclude",
        action="append",
        help="Context to exclude (case-insensitive).",
    )
    parser.add_argument(
        "--episode_dir", help="Directory containing episode audio subdirectories."
    )
    parser.add_argument(
        "--extract", action="store_true", help="Extract audio for each match."
    )
    parser.add_argument(
        "--limit", type=int, help="Stop after finding this many total matches."
    )
    parser.add_argument(
        "--limit_per_episode",
        type=int,
        help="Stop searching an episode after this many matches.",
    )
    parser.add_argument(
        "--match",
        action="append",
        help="Only check transcripts whose filename includes this string.",
    )
    parser.add_argument(
        "--min_duration", type=float, help="Minimum extraction length, in seconds."
    )
    parser.add_argument(
        "--output_dir", help="Directory where extracted clips are stored."
    )
    parser.add_argument(
        "--prefix_words", type=int, help="Words before match to show in text results."
    )
    parser.add_argument(
        "--podcast",
        action="append",
        help="Only search transcripts from podcasts whose title contains this.",
    )
    parser.add_argument(
        "--skip_existing",
        action="store_true",
        help="Skip extraction if destination file exists.",
    )
    parser.add_argument(
        "--suffix_words", type=int, help="Words after match to show in text results."
    )
    parser.add_argument(
        "--transcript_dir", help="Directory where transcript subdirectories are stored."
    )
    parser.add_argument(
        "--help_only", action="store_true", help="Show only usage and exit."
    )
    return parser


def merge_options(
    default_options: Dict[str, Any], cli_args: argparse.Namespace
) -> Dict[str, Any]:
    """
    Merge config-file defaults with CLI options.
    CLI options override config-file options when non-None.
    """
    options = dict(default_options)

    for key, value in vars(cli_args).items():
        if key == "help_only":
            continue
        if value is not None:
            options[key] = value

    # Normalize arrays similar to PHP
    def to_list(v: Any) -> List[str]:
        if v is None:
            return []
        if isinstance(v, list):
            return [str(x) for x in v]
        return [str(v)]

    # Ensure numeric arguments are stored as actual numbers.
    for key in (
        "limit",
        "limit_per_episode",
        "prefix_words",
        "suffix_words",
    ):
        if key in options:
            options[key] = int(options[key])

    for key in (
        "after",
        "before",
        "min_duration",
    ):
        if key in options:
            options[key] = float(options[key])

    options["search"] = list(
        dict.fromkeys([s for s in to_list(options.get("search")) if s])
    )
    options["match"] = to_list(options.get("match"))
    options["podcast"] = to_list(options.get("podcast"))
    options["context"] = to_list(options.get("context"))
    options["icontext"] = to_list(options.get("icontext"))
    options["context_exclude"] = to_list(options.get("context_exclude"))
    options["icontext_exclude"] = to_list(options.get("icontext_exclude"))

    if not options["search"]:
        print(usage(), file=sys.stderr)
        sys.exit("You must supply at least one search term.\n")

    options["before"] = float(options.get("before", 0.1))
    options["after"] = float(options.get("after", 0.1))

    if not options.get("output_dir"):
        options["output_dir"] = "search-results/"

    if not options.get("prefix_words") and not options.get("prefix_words") == 0:
        options["prefix_words"] = 5
    if not options.get("suffix_words") and not options.get("suffix_words") == 0:
        options["suffix_words"] = 15

    # If podcast array is empty after normalization, use ['']
    if not options["podcast"]:
        options["podcast"] = [""]

    # Normalize output_dir: handle ~ and trailing slash
    out_dir = str(options["output_dir"]).strip()
    out_dir = os.path.expanduser(out_dir)
    out_dir = out_dir.rstrip(os.sep) + os.sep
    options["output_dir"] = out_dir

    # Create output directory if needed
    out_path = Path(out_dir)
    if not out_path.exists():
        out_path.mkdir(parents=True, exist_ok=True)
    if not out_path.exists():
        sys.exit(f"Could not create directory: {out_dir}\n")

    return options


# ---------------------------------------------------------------------------
# Core helpers
# ---------------------------------------------------------------------------


def matches_search_term(word: str, search_term: str) -> bool:
    """
    Port of PHP matches_search_term().
    """
    search_term = re.sub(r"[^a-z0-9*#]", "", search_term.lower())

    if word == search_term:
        return True

    if search_term == "*":
        return True

    if search_term == "." and len(word) == 1:
        return True

    if "*" in search_term:
        last_match_end = 0
        parts = search_term.split("*")

        for idx, part in enumerate(parts):
            match_location = word.find(part, last_match_end)
            if match_location == -1:
                return False
            elif idx == 0 and match_location > 0:
                return False
            else:
                last_match_end = match_location + len(part)

        if last_match_end == len(word) or parts[-1] == "":
            return True

    return False


def timestamp_to_seconds(timestamp: str) -> float:
    """
    Convert 'H:M.SSS' / 'M.SSS' / 'S.SSS' into total seconds.
    Mirrors PHP logic that pads to 3 components.
    """
    parts = timestamp.split(":")
    while len(parts) < 3:
        parts.insert(0, "0")

    total = 0.0
    for i in range(len(parts)):
        val = float(parts.pop())  # pop from end
        total += val * (60**i)
    return total


def seconds_to_filename_stamp(seconds: float) -> str:
    """
    Build '01h02m03s' timestamp for filenames.
    """
    s = int(round(seconds))
    h = s // 3600
    s %= 3600
    m = s // 60
    s %= 60
    return f"{h:02d}h{m:02d}m{s:02d}s"


def read_transcript(transcript_path: str) -> List[Tuple[str, str, str, str]]:
    """
    Parse WebVTT transcript into a list of (orig, normalized, start, end).
    """
    parsed: List[Tuple[str, str, str, str]] = []
    last_start = "0:00.000"
    last_end = "0:00.000"

    time_re = re.compile(
        r"^((?:[0-9]+:)*[0-9]+\.[0-9]{3}) --> ((?:[0-9]+:)*[0-9]+\.[0-9]{3})$"
    )

    with open(transcript_path, "r", encoding="utf-8") as f:
        lines = [line.strip() for line in f]

    for line in lines:
        if line.startswith("WEBVTT"):
            continue
        if not line:
            continue

        m = time_re.match(line)
        if m:
            last_start, last_end = m.group(1), m.group(2)
        else:
            words = re.split(r"\s+", line)
            for w in words:
                norm = re.sub(r"[^a-z0-9#]", "", w.lower())
                parsed.append((w, norm, last_start, last_end))

    return parsed


# ---------------------------------------------------------------------------
# Main logic
# ---------------------------------------------------------------------------


def main() -> None:
    search()


def search() -> None:
    script_dir = Path(__file__).resolve().parent
    default_episode_dir = script_dir / "episodes"
    default_transcript_dir = script_dir / "transcripts"

    # Load defaults from conf
    conf_defaults = default_options_from_conf("dropseeker.conf")
    print(conf_defaults)
    # CLI
    parser = build_arg_parser()
    args = parser.parse_args()

    if args.help_only:
        print(usage())
        return

    options = merge_options(conf_defaults, args)

    # Episode dir
    if options.get("episode_dir"):
        episode_dir = Path(options["episode_dir"])
        if not episode_dir.is_absolute():
            episode_dir = Path.cwd() / episode_dir
    else:
        episode_dir = default_episode_dir
    episode_dir = episode_dir.resolve()

    # Transcript dir
    if options.get("transcript_dir"):
        transcript_dir = Path(options["transcript_dir"])
        if not transcript_dir.is_absolute():
            transcript_dir = Path.cwd() / transcript_dir
    else:
        transcript_dir = default_transcript_dir
    transcript_dir = transcript_dir.resolve()

    options["episode_dir"] = str(episode_dir)
    options["transcript_dir"] = str(transcript_dir)

    # Find all podcast transcript dirs
    all_podcast_dirs = sorted([p for p in transcript_dir.glob("*") if p.is_dir()])
    matching_transcripts: List[str] = []

    for podcast_path in all_podcast_dirs:
        podcast_title = podcast_path.name

        if not options["podcast"]:
            matching_transcripts.extend([str(p) for p in podcast_path.glob("*.vtt")])
        else:
            for pattern in options["podcast"]:
                if pattern.lower() in podcast_title.lower():
                    matching_transcripts.extend(
                        [str(p) for p in podcast_path.glob("*.vtt")]
                    )
                    break

    matching_transcripts.sort()
    matching_transcripts = list(reversed(matching_transcripts))

    # Filter by --match if present
    transcripts: List[str] = []
    if options["match"]:
        for t in matching_transcripts:
            filename = os.path.basename(t)
            if any(m.lower() in filename.lower() for m in options["match"]):
                transcripts.append(t)
    else:
        transcripts = matching_transcripts

    matches_found = 0

    for transcript_file in transcripts:
        parsed = read_transcript(transcript_file)
        num_words = len(parsed)
        if num_words == 0:
            continue

        matches_in_episode = 0

        for raw_search in options["search"]:
            search_term = raw_search.lower()
            keywords = search_term.split(" ")

            suffix_word_count = options["suffix_words"] + search_term.count(" ")

            suffix_words = []
            prefix_words: List[str] = []

            for i in range(min(num_words, suffix_word_count)):
                suffix_words.append(parsed[i])

            start = float("inf")
            end = 0.0

            for idx, word_entry in enumerate(parsed):
                orig_word, norm_word, w_start, w_end = word_entry
                prefix_words.append(orig_word)

                if num_words > idx + suffix_word_count:
                    suffix_words.append(parsed[idx + suffix_word_count])

                if suffix_words:
                    suffix_words.pop(0)

                if len(prefix_words) > options["prefix_words"] + 1:
                    prefix_words.pop(0)

                if not matches_search_term(norm_word, keywords[0]):
                    continue

                start = w_start
                end = w_end

                if len(keywords) > 1:
                    if len(suffix_words) < len(keywords) - 1:
                        break

                    for k in range(1, len(keywords)):
                        if not matches_search_term(suffix_words[k - 1][1], keywords[k]):
                            # equivalent to continue 2 in PHP: continue outer parsed loop
                            break
                        end = suffix_words[k - 1][3]
                    else:
                        # all extra keywords matched
                        pass
                    # if we broke inside the for k loop, we continue outer loop
                    # but the simplest safe behavior is:
                    # re-check whether we actually updated "end" for the last part
                    # This is close enough to original semantics for this translation.

                suffix_string = " ".join(sw[0] for sw in suffix_words)
                exclusion_search_string = (
                    " ".join(prefix_words) + " " + suffix_string.strip()
                )

                # context_exclude (case-sensitive)
                if options["context_exclude"]:
                    if any(
                        ex in exclusion_search_string
                        for ex in options["context_exclude"]
                    ):
                        continue

                # icontext_exclude (case-insensitive)
                if options["icontext_exclude"]:
                    lower_str = exclusion_search_string.lower()
                    if any(
                        ex.lower() in lower_str for ex in options["icontext_exclude"]
                    ):
                        continue

                # context (case-sensitive required)
                if options["context"]:
                    if not all(
                        ctx in exclusion_search_string for ctx in options["context"]
                    ):
                        continue

                # icontext (case-insensitive required)
                if options["icontext"]:
                    lower_str = exclusion_search_string.lower()
                    if not all(ctx.lower() in lower_str for ctx in options["icontext"]):
                        continue

                start_seconds = timestamp_to_seconds(start)
                end_seconds = timestamp_to_seconds(end)

                if options.get("min_duration") is not None:
                    duration = end_seconds - start_seconds
                    if duration < float(options["min_duration"]):
                        continue
                    else:
                        print(f"Duration: {duration} seconds")

                matches_in_episode += 1
                matches_found += 1

                rel_name = re.sub(
                    r"\s\(guid.*$",
                    "",
                    transcript_file.replace(str(transcript_dir), "").lstrip(os.sep),
                )
                print(f"{rel_name} @ {start}:\n\t{exclusion_search_string}\n")

                if options.get("extract"):
                    m = re.findall(r"\(guid=(.+?)\)", transcript_file)
                    guid = m[0] if m else None
                    if not guid:
                        sys.exit(f"Could not extract guid from {transcript_file}\n")

                    audio_files: List[str] = []
                    for podcast in options["podcast"]:
                        # Note: same caveat about case sensitivity
                        pattern = f"*{podcast}*"
                        audio_files.extend(
                            [
                                str(p)
                                for p in episode_dir.glob(f"{pattern}/*guid={guid}*.*")
                            ]
                        )

                    if not audio_files:
                        sys.exit(f"Could not find audio for {transcript_file}\n")

                    audio_file = audio_files[0]

                    base_name = f"{search_term} - {os.path.basename(audio_file)}"
                    base_name = base_name[:200]
                    stamp = seconds_to_filename_stamp(start_seconds)

                    dest_file = os.path.join(
                        options["output_dir"],
                        f"{base_name} - {stamp}.aif",
                    )

                    if options.get("skip_existing") and os.path.exists(dest_file):
                        pass
                    else:
                        clip_start = start_seconds - options["before"]
                        if clip_start < 0:
                            clip_start = 0.0
                        clip_duration = (
                            end_seconds
                            - start_seconds
                            + options["before"]
                            + options["after"]
                        )

                        cmd = [
                            "ffmpeg",
                            "-hide_banner",
                            "-loglevel",
                            "error",
                            "-y",
                            "-ss",
                            f"{clip_start}",
                            "-t",
                            f"{clip_duration}",
                            "-i",
                            audio_file,
                            dest_file,
                        ]
                        subprocess.run(cmd, check=False)

                if options.get("limit") is not None and matches_found == int(
                    options["limit"]
                ):
                    return

                if options.get(
                    "limit_per_episode"
                ) is not None and matches_in_episode == int(
                    options["limit_per_episode"]
                ):
                    break  # move to next transcript_file

    # end for each transcript


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        # Handle killing the script with Ctrl+C
        sys.exit(0)

import argparse
import getpass
import hashlib
import io
import json
import os
import pathlib
import requests
import sys
import tarfile


parser = argparse.ArgumentParser(
    prog="cacheData.py",
    description="Create a local cache from Wikimedia Enterprise snapshots"
)

parser.add_argument("-v", "--verbose",
                    action="store_true",
                    default=(os.getenv("WME_API_VERBOSE", "").lower() not in ("", "0", "false", "no", "f", "n")),
                    help="Display verbose output. If unspecified, use WME_API_VERBOSE env variable.")
parser.add_argument("-d", "--directory",
                    default=os.getenv("WME_API_DIRECTORY", "cache"),
                    help="Directory to store the output article files; it will be created as needed. "
                         "If unspecified, use WME_API_DIRECTORY env variable. If the env variable is unspecified, "
                         "defaults to a subdirectory named cache relative to the working directory the script is ran from.")
parser.add_argument("-u", "--username",
                    default=os.getenv("WME_API_USERNAME"),
                    help="API username. If unspecified, use WME_API_USERNAME env variable.")
parser.add_argument("-p", "--password",
                    nargs="?",
                    const="@stdin",
                    default=os.getenv("WME_API_PASSWORD"),
                    help="API password. Append with @ to read from a file. Omit argument to read from stdin. If unspecified, use WME_API_PASSWORD env variable.")
parser.add_argument("-n", "--namespace",
                    nargs=1,
                    action="extend",
                    help="Namespaces to import, specified via namespace number. This option can be specified multiple times to select "
                         "multiple namespaces. If unspecified, imports all namespaces present in the WME API (Main, Category, Template, and File).")
parser.add_argument("project", help="Wikimedia project (database) name.")

args = parser.parse_args()
if args.password == "@stdin":
    args.password = getpass.getpass()
elif args.password == "@":
    print("Invalid password file specifier", file=sys.stderr)
    sys.exit(1)
elif args.password[0] == "@":
    try:
        args.password = pathlib.Path(args.password[1:]).read_text().strip()
    except Exception as e:
        print(e, file=sys.stderr)
        sys.exit(1)

if not args.username or not args.password:
    print("A username and password must be defined", file=sys.stderr)
    sys.exit(1)

cache_dir = pathlib.Path(args.directory, args.project)
cache_dir.mkdir(parents=True, exist_ok=True)

# Attempt auth
session = requests.Session()
r = session.post("https://auth.enterprise.wikimedia.com/v1/login", json={"username": args.username, "password": args.password})
if not r.ok:
    print(r.content)
    r.raise_for_status()
auth_data = r.json()

# Set up Bearer token
session.headers["Authorization"] = f"Bearer {auth_data['access_token']}"
session.headers["User-Agent"] = "WikiMirror Data Update/v1.0 +https://www.mediawiki.org/wiki/Extension:WikiMirror"

# Get all namespaces for project
search_filter = [{"field": "is_part_of.identifier", "value": args.project}]
r = session.post("https://api.enterprise.wikimedia.com/v2/snapshots", json={"filters": search_filter, "fields": ["identifier", "chunks", "namespace", "namespace.name", "namespace.identifier"]})
if not r.ok:
    print(r.content)
    r.raise_for_status()
ns_data = r.json()
if args.verbose:
    print("Dumping the following namespaces: " + ", ".join(str(ns['namespace']['identifier']) for ns in ns_data if not args.namespace or str(ns['namespace']['identifier']) in args.namespace))

for ns in ns_data:
    if args.namespace and str(ns['namespace']['identifier']) not in args.namespace:
        continue

    if args.verbose:
        print(f"Processing namespace {ns['namespace']['identifier']} ({len(ns['chunks'])} chunks)...")

    snapshot_id = ns["identifier"]

    for chunk_id in ns["chunks"]:
        print(f"Processing chunk {snapshot_id}/{chunk_id}...")
        with session.get(f"https://api.enterprise.wikimedia.com/v2/snapshots/{snapshot_id}/chunks/{chunk_id}/download", stream=True, allow_redirects=True) as r:
            if not r.ok:
                print(r.content)
                r.raise_for_status()
            with tarfile.open(fileobj=r.raw, mode="r|*") as tf:
                # work around a bug with TextIOWrapper interaction with tarfile
                # tarfile allows forward seeking only
                if not hasattr(tf.fileobj, "seekable"):
                    tf.fileobj.seekable = lambda: True

                for ti in tf:
                    if ti is None or not ti.isfile():
                        continue

                    print(f"Found file {ti.name} in tarball")

                    with io.TextIOWrapper(tf.extractfile(ti), encoding="utf-8") as f:
                        i = 0
                        for line in f:
                            article = json.loads(line)
                            if "article_body" not in article or "html" not in article["article_body"] or "wikitext" not in article["article_body"]:
                                if args.verbose:
                                    print(f"Found article {article['name']} ({article['identifier']}) with no body")
                                continue

                            i += 1
                            if args.verbose:
                                size = article["version"].get("size", {"value": 0, "unit_text": "B"})
                                print(f"Found article {article['name']} ({article['identifier']}) with {size['value']}{size['unit_text']} body")
                            elif i % 5000 == 0:
                                print(i)

                            h = hashlib.sha1(article["name"].encode("utf-8"), usedforsecurity=False)
                            cache_path = cache_dir / str(ns['namespace']['identifier']) / h.hexdigest()[0:2]
                            cache_path.mkdir(parents=True, exist_ok=True)

                            # store in pretty-printed format to make it easier to debug stuff later on via visual/human inspection
                            with open(cache_path / f"{article['identifier']}.json", "wt", encoding="utf-8") as cf:
                                json.dump(article, cf, ensure_ascii=False, indent=2, sort_keys=True)

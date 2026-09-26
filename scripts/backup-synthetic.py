#!/usr/bin/env python3
"""Create an authenticated encrypted backup of an isolated synthetic drill."""
import argparse
import sys

sys.dont_write_bytecode = True
from backup_restore import Drill

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("context", help="Private context created by verify-restore.py; live projects are rejected.")
args = parser.parse_args()
Drill(args.context).backup()

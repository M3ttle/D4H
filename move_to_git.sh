#!/bin/bash
# Copy all files and folders to a folder located ../D4H-git/...

# Get the absolute path of the current directory
current_dir=$(pwd)

# Get the absolute path of the ../D4H-git/ directory
git_dir=$(realpath ../D4H-git)

# Copy all files and folders to the ../D4H-git/ directory
cp -r $current_dir/* $git_dir

# Print a message to the user
echo "All files and folders have been copied to the ../D4H-git/ directory"
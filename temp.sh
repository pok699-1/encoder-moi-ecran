#!/bin/bash

INPUT="storage/app/public/files/1/input.mov"
OUTPUT="storage/app/public/files/1/output_compressed.mp4"

start=$(date +%s)

# Размер исходного файла
input_size=$(stat -c%s "$INPUT")
input_size_mb=$(echo "scale=2; $input_size/1024/1024" | bc)

# Получаем разрешение
resolution=$(ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0:s=x "$INPUT")
width=$(echo $resolution | cut -d'x' -f1)
height=$(echo $resolution | cut -d'x' -f2)

# Ограничиваем до 1080p
if [ "$height" -gt 1080 ]; then
    scale_arg="-vf scale=-2:1080"
else
    scale_arg=""
fi

# Сжатие через CRF (авто-битрейт) с FPS 30
ffmpeg -i "$INPUT" \
  -c:v libx264 -crf 28 -preset fast -r 30 $scale_arg \
  -c:a aac -b:a 96k \
  "$OUTPUT"

# Размер сжатого файла
output_size=$(stat -c%s "$OUTPUT")
output_size_mb=$(echo "scale=2; $output_size/1024/1024" | bc)

end=$(date +%s)
runtime=$((end - start))

echo "Команда выполнялась $runtime секунд"
echo "Исходный размер: ${input_size_mb} MB"
echo "Размер после сжатия: ${output_size_mb} MB"

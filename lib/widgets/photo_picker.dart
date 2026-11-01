import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../theme.dart';

/// Photos chosen in the app, ready to upload: (file name, bytes).
typedef PickedPhoto = (String, Uint8List);

/// "Take photo" / "Choose photos" with small previews and remove buttons.
class PhotoPicker extends StatefulWidget {
  const PhotoPicker({super.key, required this.onChanged, this.max = 4, this.label = 'Photos'});

  final ValueChanged<List<PickedPhoto>> onChanged;
  final int max;
  final String label;

  @override
  State<PhotoPicker> createState() => _PhotoPickerState();
}

class _PhotoPickerState extends State<PhotoPicker> {
  final _picker = ImagePicker();
  final List<PickedPhoto> _photos = [];

  Future<void> _add(ImageSource source) async {
    final left = widget.max - _photos.length;
    if (left <= 0) return;

    // Photos are resized on the phone first: smaller uploads on slow data.
    final files = source == ImageSource.camera
        ? [if (await _picker.pickImage(source: source, maxWidth: 1600, imageQuality: 80) case final XFile file) file]
        : await _picker.pickMultiImage(maxWidth: 1600, imageQuality: 80, limit: left > 1 ? left : null);

    for (final file in files.take(left)) {
      _photos.add((file.name, await file.readAsBytes()));
    }
    setState(() {});
    widget.onChanged(List.of(_photos));
  }

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('${widget.label} (${_photos.length}/${widget.max})', style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (var i = 0; i < _photos.length; i++)
            Stack(children: [
              ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.memory(_photos[i].$2, width: 72, height: 72, fit: BoxFit.cover)),
              Positioned(
                right: 0,
                top: 0,
                child: InkWell(
                  onTap: () {
                    setState(() => _photos.removeAt(i));
                    widget.onChanged(List.of(_photos));
                  },
                  child: const CircleAvatar(radius: 11, backgroundColor: Colors.black54, child: Icon(Icons.close, size: 14, color: Colors.white)),
                ),
              ),
            ]),
          if (_photos.length < widget.max) ...[
            if (!kIsWeb)
              _AddButton(icon: Icons.photo_camera_outlined, label: 'Camera', onTap: () => _add(ImageSource.camera)),
            _AddButton(icon: Icons.photo_library_outlined, label: 'Gallery', onTap: () => _add(ImageSource.gallery)),
          ],
        ]),
      ]);
}

class _AddButton extends StatelessWidget {
  const _AddButton({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(8),
        child: Container(
          width: 72,
          height: 72,
          decoration: BoxDecoration(border: Border.all(color: WiBuyColors.green), borderRadius: BorderRadius.circular(8)),
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(icon, color: WiBuyColors.green),
            Text(label, style: const TextStyle(fontSize: 11, color: WiBuyColors.green)),
          ]),
        ),
      );
}

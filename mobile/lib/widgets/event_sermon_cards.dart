import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models/models.dart';
import '../theme/app_theme.dart';

class EventCard extends StatelessWidget {
  final ChurchEvent event;
  final VoidCallback onTap;
  const EventCard({super.key, required this.event, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final start = DateTime.tryParse(event.startAt);
    return Card(
      clipBehavior: Clip.antiAlias,
      margin: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AspectRatio(
              aspectRatio: 16 / 9,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  Container(color: AppColors.bg2),
                  if (event.coverImageUrl != null)
                    CachedNetworkImage(imageUrl: event.coverImageUrl!, fit: BoxFit.cover),
                  if (start != null)
                    Positioned(
                      top: 12,
                      left: 12,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.75), borderRadius: BorderRadius.circular(10)),
                        child: Column(children: [
                          Text(DateFormat('d').format(start), style: const TextStyle(color: AppColors.goldSoft, fontWeight: FontWeight.w800, fontSize: 18, height: 1)),
                          Text(DateFormat('MMM').format(start).toUpperCase(), style: const TextStyle(color: AppColors.inkDim, fontSize: 10)),
                        ]),
                      ),
                    ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(event.title, style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 6),
                  Row(children: [
                    if (start != null) Text(DateFormat('g:mm a').format(start), style: const TextStyle(color: AppColors.inkFaint, fontSize: 12.5)),
                    if (event.location != null) ...[
                      const SizedBox(width: 12),
                      Flexible(child: Text('· ${event.location}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 12.5), overflow: TextOverflow.ellipsis)),
                    ],
                  ]),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class SermonCard extends StatelessWidget {
  final Sermon sermon;
  final VoidCallback onTap;
  const SermonCard({super.key, required this.sermon, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final published = DateTime.tryParse(sermon.publishedAt);
    return Card(
      clipBehavior: Clip.antiAlias,
      margin: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
      child: InkWell(
        onTap: onTap,
        child: Row(
          children: [
            SizedBox(
              width: 100,
              height: 100,
              child: sermon.coverImageUrl != null
                  ? CachedNetworkImage(imageUrl: sermon.coverImageUrl!, fit: BoxFit.cover)
                  : Container(color: AppColors.bg2, child: const Icon(Icons.menu_book, color: AppColors.inkFaint)),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(sermon.title, style: Theme.of(context).textTheme.titleLarge, maxLines: 2, overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 4),
                    Text(
                      [if (sermon.speaker != null) sermon.speaker!, if (published != null) DateFormat('MMM d, yyyy').format(published)].join(' · '),
                      style: const TextStyle(color: AppColors.inkFaint, fontSize: 12),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

<h2><?= $headline ?></h2>

<div class="explore-content">
	<? if (!empty($image)): ?>
		<figure>
			<img src='<?= $image; ?>' class='image'/>
		</figure>
	<? endif; ?>
	<? if (!empty($linkgroups)): ?>
		<? foreach ($linkgroups as $linkgroup): ?>
			<? if (!empty($linkgroup['links'])): ?>
				<h4><?= $linkgroup['headline']; ?></h4>
				<ul>
					<? foreach ($linkgroup['links'] as $singlelink): ?>
						<li>
							<a href='<?= $singlelink['href']; ?>'><?= $singlelink['anchor']; ?></a>
						</li>
					<? endforeach; ?>
				</ul>
			<? endif; ?>
		<? endforeach; ?>
	<? endif; ?>
</div>
#!/usr/bin/python3
import re
import GwentUtils

IMAGE_SIZES = ['original', 'high', 'medium', 'low', 'thumbnail']

"""
Constants from Gwent Client.
"""
COMMON = 1
RARE = 2
EPIC = 4
LEGENDARY = 8

LEADER = 1
BRONZE = 2
SILVER = 4
GOLD = 8

NEUTRAL = 1
MONSTER = 2
NILFGAARD = 4
NORTHERN_REALMS = 8
SCOIATAEL = 16
SKELLIGE = 32

"""
Mill and Crafting values for each rarity.
"""
CRAFT_VALUES = {}
CRAFT_VALUES[COMMON] = {"standard": 30, "premium": 200, "upgrade": 100}
CRAFT_VALUES[RARE] = {"standard": 80, "premium": 400, "upgrade": 200}
CRAFT_VALUES[EPIC] = {"standard": 200, "premium": 800, "upgrade": 300}
CRAFT_VALUES[LEGENDARY] = {"standard": 800, "premium": 1600, "upgrade": 400}

MILL_VALUES = {}
MILL_VALUES[COMMON] = {"standard": 10, "premium": 10, "upgrade": 20}
MILL_VALUES[RARE] = {"standard": 20, "premium": 20, "upgrade": 50}
MILL_VALUES[EPIC] = {"standard": 50, "premium": 50, "upgrade": 80}
MILL_VALUES[LEGENDARY] = {"standard": 200, "premium": 200, "upgrade": 120}

"""
Gwent Client ID -> Gwent Data ID mapping.
"""
RARITIES = { COMMON: "Common", RARE: "Rare", EPIC: "Epic", LEGENDARY: "Legendary"}
TYPES = { LEADER: "Leader", BRONZE: "Bronze", SILVER: "Silver", GOLD: "Gold"}
FACTIONS = { NEUTRAL: "Neutral", MONSTER: "Monster", NILFGAARD: "Nilfgaard",
    NORTHERN_REALMS: "Northern Realms", SCOIATAEL: "Scoiatael", SKELLIGE: "Skellige"}

# Gaunter's 'Higher than 5' and 'Lower than 5' are not actually cards.
INVALID_TOKENS = ['200175', '200176']

"""
 Use the ID from here to look up localisations in categories output file.
 WARNING : this list is not simply 1 to 73, some numbers are missing
"""
CATEGORIES = [1, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 2, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 3, 30, 
               31, 32, 33, 34, 35, 36, 37, 38, 39, 4, 40, 41, 42, 43, 44, 46, 47, 48, 49, 5, 50, 51, 52, 53, 
               54, 55, 56, 57, 58, 59, 6, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 7, 70, 71, 8, 9, 73]
LAST_CATEGORY = max(CATEGORIES)

def create_card_json(gwent_data_helper, patch):
    # Replace with these values {0} : card id, {1} : variation id, {2} : image size
    imageUrl = "https://firebasestorage.googleapis.com/v0/b/gwent-9e62a.appspot.com/o/images%2F" +\
                    patch + "%2F{0}%2F{1}%2F{2}.png?alt=media"

    cards = {}

    card_templates = gwent_data_helper.card_templates
    for template_id in card_templates:
        template = card_templates[template_id]
        card = {}
        card_id = template.attrib['Id']
        card['ingameId'] = card_id
        card['strength'] = int(template.find('Power').text)
        card['type'] = TYPES.get(int(template.find('Tier').text))
        card['faction'] = FACTIONS.get(int(template.find('FactionId').text))

        card['name'] = {}
        card['flavor'] = {}
        for region in GwentUtils.LOCALES:
            card['name'][region] = gwent_data_helper.card_names.get(region).get(card_id)
            card['flavor'][region] = gwent_data_helper.flavor_strings.get(region).get(card_id)

        # False by default, will be set to true if collectible or is a token of a released card.
        card['released'] = False

        # Tooltips
        card['info'] = {}
        card['infoRaw'] = {}
        for locale in GwentUtils.LOCALES:
            tooltip = gwent_data_helper.tooltips[locale].get(card_id)
            if tooltip is not None:
                card['infoRaw'][locale] = tooltip
                card['info'][locale] = GwentUtils.clean_html(tooltip)

        # Keywords.
        card['keywords'] = gwent_data_helper.keywords.get(card_id)

        # Units no longer have a row restriction.
        card['positions'] = ["Melee", "Ranged", "Siege"]

        # Loyalty
        card['loyalties'] = []
        placement = template.find('Placement')
        if placement.attrib['PlayerSide'] != "0":
            card['loyalties'].append("Loyal")
        if placement.attrib['OpponentSide'] != "0":
            card['loyalties'].append("Disloyal")

        # Categories
        card['categories'] = []
        card['categoryIds'] = []
        categoriesSum = int(template.find('Categories').find('e0').attrib['V']);
        # XML Card category is the sum of all categories of the card
        for category in range(LAST_CATEGORY, 1, -1):
            categoryPower = 2**category
            if categoriesSum - categoryPower >= 0:
                categoriesSum -= categoryPower
                if category in CATEGORIES:
                    card['categoryIds'].append("card_category_" + str(category))
                if categoriesSum == 0:
                    break

        categories_en_us = gwent_data_helper.categories["en-US"]
        for category_id in card['categoryIds']:
            if category_id in categories_en_us:
                card['categories'].append(categories_en_us[category_id])

        # Variations no longer exist in Gwent. To maintain backwards compatability, create 1 variation.
        card['variations'] = {}
        variation = {}
        variation_id = card_id + "00" # Old variation id format.

        availability = int(template.attrib['Availability'])
        if availability == 1:
            variation['availability'] = "BaseSet"
        else:
            variation['availability'] = "NonOwnable"

        variation['variationId'] = variation_id

        collectible = availability == 1
        variation['collectible'] = collectible

        # If a card is collectible, we know it has been released.
        if collectible:
            card['released'] = True

        rarity = int(template.find('Rarity').text)
        variation['rarity'] = RARITIES.get(rarity)

        variation['craft'] = CRAFT_VALUES.get(rarity)
        variation['mill'] = MILL_VALUES.get(rarity)

        art = {}
        if collectible:
            for image_size in IMAGE_SIZES:
                art[image_size] = imageUrl.format(card['ingameId'], variation_id, image_size)
        art_definition = template.find('ArtDefinition')

        if art_definition != None:
            art['ingameArtId'] = art_definition.attrib.get('ArtId')

        variation['art'] = art

        card['variations'][variation_id] = variation

        # Add all token cards to the 'related' list.
        tokens = gwent_data_helper.tokens.get(card['ingameId'])
        card['related'] = tokens

        cards[card_id] = card

    # Check tokens are correctly marked as released.
    for card_id in cards:
        card = cards[card_id]
        if card['released'] and card.get('related') is not None:
            for token_id in card.get('related'):
                if token_id in cards:
                    cards[token_id]['released'] = token_id not in INVALID_TOKENS

    return cards

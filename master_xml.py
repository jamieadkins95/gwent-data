#!/usr/bin/python3
import xml.etree.ElementTree as xml
import sys
import os
import json
import re
import argparse

from datetime import datetime
from pprint import pprint
from unidecode import unidecode


parser = argparse.ArgumentParser(description="Transform the Gwent card data contained in xml files into a "
                                             "standardised JSON format.",
                                 epilog="Usage example:\n./master_xml.py ./pathToXML v0-9-10",
                                 formatter_class=argparse.RawTextHelpFormatter)
parser.add_argument("inputFolder", help="Folder containing the xml files.")
parser.add_argument("patch", help="Specifies the Gwent patch version.")
args = parser.parse_args()
PATCH = args.patch
xml_folder = args.inputFolder

# Replace with these values {0} : card id, {1} : variation id, {0} : image size
IMAGE_URL = "https://firebasestorage.googleapis.com/v0/b/gwent-9e62a.appspot.com/o/images%2F" + PATCH + "%2F{0}%2F{1}%2F{2}.png?alt=media"
IMAGE_SIZES = ['original', 'high', 'medium', 'low', 'thumbnail']

def saveJson(filename, cardList):
    filepath = os.path.join(xml_folder + "../" + filename)
    print("Saving %s cards to: %s" % (len(cardList), filepath))
    with open(filepath, "w", encoding="utf-8", newline="\n") as f:
        json.dump(cardList, f, sort_keys=True, indent=2, separators=(',', ': '))

def cleanHtml(raw_html):
    cleanr = re.compile('<.*?>')
    cleantext = re.sub(cleanr, '', raw_html)
    return cleantext

def getRawTooltips(locale):
    TOOLTIP_STRINGS_PATH = xml_folder + "tooltips_" + locale + ".csv"
    if not os.path.isfile(TOOLTIP_STRINGS_PATH):
        print("Couldn't find " + locale + " tooltips at " + TOOLTIP_STRINGS_PATH)
        exit()

    rawTooltips = {}

    tooltipsFile = open(TOOLTIP_STRINGS_PATH, "r", encoding="utf8")
    for tooltip in tooltipsFile:
        split = tooltip.split("\";\"")
        if len(split) < 2:
            continue
        tooltipId = split[1].replace("tooltip_","").replace("_description","").replace("\"", "").lstrip("0")

        # Remove any quotation marks and new lines.
        rawTooltips[tooltipId] = split[2].replace("\"\n", "").replace("\\n", "\n")

    return rawTooltips

def getAbilityData():

    CARD_ABILITIES_PATH = xml_folder + "GwentCardAbilities.xml"
    if not os.path.isfile(CARD_ABILITIES_PATH):
        print("Couldn't find abilities.xml at " + CARD_ABILITIES_PATH)
        exit()

    abilities = {}

    tree = xml.parse(CARD_ABILITIES_PATH)
    root = tree.getroot()

    for ability in root.iter('Ability'):
        abilities[ability.attrib['id']] = ability

    return abilities

def getAbilityValue(abilityId, paramName):
    ability = ABILITIES.get(abilityId)
    if ability == None:
        return None
    if ability.find(paramName) != None:
        return ability.find(paramName).attrib['V']

def getTooltipData():
    TOOLTIPS_PATH = xml_folder + "GwentTooltips.xml"
    if not os.path.isfile(TOOLTIPS_PATH):
        print("Couldn't find tooltips.xml at " + TOOLTIPS_PATH)
        exit()

    tooltipData = {}

    tree = xml.parse(TOOLTIPS_PATH)
    root = tree.getroot()

    for tooltip in root.iter('CardTooltip'):
        tooltipData[tooltip.attrib['id']] = tooltip

    return tooltipData

def evaluateInfoData(cards):
    # Now that we have the raw strings, we have to get any values that are missing.
    for cardId in cards:
        defaultEvaluated = False
        for region in LOCALES:
            # Some cards don't have info.
            if cards[cardId].get('info') == None or cards[cardId]['info'] == "":
                continue

            # Set info to be the raw tooltip string.
            tooltipId = cards[cardId]['info'][region]
            cards[cardId]['info'][region] = TOOLTIPS[region].get(tooltipId)
            result = re.findall(r'.*?\{(.*?)\}.*?', cards[cardId]['info'][region]) # Regex. Get all strings that lie between a '{' and '}'.

            tooltip = TOOLTIP_DATA.get(tooltipId)
            for key in result:
                for variable in tooltip.iter('VariableData'):
                    data = variable.find(key)
                    if data == None:
                        # This is not the right variable for this key, let's check the next one.
                        continue
                    if "crd" in key:
                        # Spawn a specific card.
                        crd = data.attrib['V']
                        if crd != "":
                            cards[cardId]['info'][region] = cards[cardId]['info'][region].replace("{" + key + "}", cards[crd]['name'][region])
                            # We've dealt with this key, move on.
                            continue
                    if variable.attrib['key'] == key:
                        # The value is sometimes given immediately here.
                        if data.attrib['V'] != "":
                            cards[cardId]['info'][region] = cards[cardId]['info'][region].replace("{" + key + "}", data.attrib['V'])
                        else: # Otherwise we are going to have to look in the ability data to find the value.
                            abilityId = variable.find(key).attrib['abilityId']
                            paramName = variable.find(key).attrib['paramName']
                            abilityValue = getAbilityValue(abilityId, paramName)
                            if abilityValue != None:
                                cards[cardId]['info'][region] = cards[cardId]['info'][region].replace("{" + key + "}", abilityValue)

            cards[cardId]['infoRaw'][region] = cards[cardId]['info'][region]
            cards[cardId]['info'][region] = cleanHtml(cards[cardId]['info'][region])

def getCardNames(locale):
    CARD_NAME_PATH = xml_folder + "cards_" + locale + ".csv"
    if not os.path.isfile(CARD_NAME_PATH):
        print("Couldn't find " + locale + " card file at " + CARD_NAME_PATH)
        exit()

    cardNames = {}

    nameFile = open(CARD_NAME_PATH, "r", encoding="utf8")
    for line in nameFile:
        split = line.split(";")
        if len(split) < 2:
            continue
        if "_name" in split[1]:
            nameId = split[1].replace("_name", "").replace("\"", "")
            # Remove any quotation marks and new lines.
            cardNames[nameId] = split[2].replace("\"", "").replace("\n", "")

    nameFile.close()
    return cardNames

def getFlavorStrings(locale):
    CARD_NAME_PATH = xml_folder + "cards_" + locale + ".csv"
    if not os.path.isfile(CARD_NAME_PATH):
        print("Couldn't find " + locale + " card file at " + CARD_NAME_PATH)
        exit()

    flavorStrings = {}

    nameFile = open(CARD_NAME_PATH, "r", encoding="utf8")
    for line in nameFile:
        split = line.split(";")
        if len(split) < 2:
            continue
        if "_fluff" in split[1]:
            flavorId = split[1].replace("_fluff", "").replace("\"", "")
            # Remove any quotation marks and new lines.
            flavorStrings[flavorId] = split[2].replace("\"", "").replace("\n", "")

    nameFile.close()
    return flavorStrings

def getCardTemplates():
    TEMPLATES_PATH = xml_folder + "GwentCardTemplates.xml"
    if not os.path.isfile(TEMPLATES_PATH):
        print("Couldn't find templates.xml at " + TEMPLATES_PATH)
        exit()

    cardTemplates = {}

    tree = xml.parse(TEMPLATES_PATH)
    root = tree.getroot()

    for template in root.iter('CardTemplate'):
        cardTemplates[template.attrib['id']] = template

    return cardTemplates

def createCardJson():
    cards = {}

    for templateId in TEMPLATES:
        template = TEMPLATES[templateId]
        card = {}
        card['ingameId'] = template.attrib['id']
        card['strength'] = int(template.attrib['power'])
        card['type'] = template.attrib['group']
        card['faction'] = template.attrib['factionId'].replace("NorthernKingdom", "Northern Realms")

        key = template.attrib['dbgStr'].lower().replace(" ", "_").replace("'", "")
        # Remove any underscores from the end.
        if key[-1] == "_":
            key = key[:-1]

        card['name'] = {}
        card['flavor'] = {}
        for region in LOCALES:
            card['name'][region] = CARD_NAMES.get(region).get(key)
            card['flavor'][region] = FLAVOR_STRINGS.get(region).get(key)

        # False by default, will be set to true if collectible or is a token of a released card.
        card['released'] = False

        if (template.find('Tooltip') != None):
            card['info'] = {}
            card['infoRaw'] = {}
            for region in LOCALES:
                # Set to tooltipId for now, we will evaluate after we have looked at every card.
                card['info'][region] = template.find('Tooltip').attrib['key']

        card['positions'] = []
        card['loyalties'] = []
        for flag in template.iter('flag'):
            key = flag.attrib['name']

            if key == "Loyal" or key == "Disloyal":
                card['loyalties'].append(key)

            if key == "Melee" or key == "Ranged" or key == "Siege" or key == "Event":
                card['positions'].append(key)

        card['categories'] = []
        for flag in template.iter('Category'):
            key = flag.attrib['id']
            if key in CATEGORIES:
                card['categories'].append(CATEGORIES.get(key))

        card['variations'] = {}

        for definition in template.find('CardDefinitions').findall('CardDefinition'):
            variation = {}
            variationId = definition.attrib['id']

            variation['variationId'] = variationId
            variation['availability'] = definition.find('Availability').attrib['V']
            collectible = variation['availability'] == "BaseSet"
            variation['collectible'] = collectible

            # If a card is collectible, we know it has been released.
            if collectible:
                card['released'] = True

            variation['rarity'] = definition.find('Rarity').attrib['V']

            variation['craft'] = CRAFT_VALUES[variation['rarity']]
            variation['mill'] = MILL_VALUES[variation['rarity']]

            art = {}
            for imageSize in IMAGE_SIZES:
                art[imageSize] = IMAGE_URL.format(card['ingameId'], variationId, imageSize)
            art['artist'] = definition.find("UnityLinks").find("StandardArt").attrib['author']
            variation['art'] = art

            card['variations'][variationId] = variation

        cards[card['ingameId']] = card

    return cards

# If a card is a token of a released card, it has also been released.
def evaluateTokens(cards):
    for cardId in cards:
        card = cards[cardId]
        if card['released']:
            card['related'] = []
            for ability in TEMPLATES[cardId].iter('Ability'):
                ability = ABILITIES.get(ability.attrib['id'])
                if ability == None:
                    continue

                # There are several different ways that a template can be referenced.
                for template in ability.iter('templateId'):
                    tokenId = template.attrib['V']
                    token = cards.get(tokenId)
                    if isTokenValid(token):
                        cards.get(tokenId)['released'] = True
                        card['related'].append(tokenId)

                for template in ability.iter('TemplatesFromId'):
                    for token in template.iter('id'):
                        tokenId = token.attrib['V']
                        token = cards.get(tokenId)
                        if isTokenValid(token):
                            cards.get(tokenId)['released'] = True
                            card['related'].append(tokenId)

                for template in ability.iter('TransformTemplate'):
                    tokenId = template.attrib['V']
                    token = cards.get(tokenId)
                    if isTokenValid(token):
                        cards.get(tokenId)['released'] = True
                        card['related'].append(tokenId)

                for template in ability.iter('TemplateId'):
                    tokenId = template.attrib['V']
                    token = cards.get(tokenId)
                    if isTokenValid(token):
                        token['released'] = True
                        card['related'].append(tokenId)

def isTokenValid(token):
    if token != None and token.get('info') != None:
        valid = True
        for region in token['info']:
            if token['info'].get(region) == None or token['info'][region] == '':
                valid = False
        return valid
    else:
        return False

def evaluateKeywords(cards):
    for cardId in cards:
        card = cards[cardId]
        if card.get('infoRaw') == None:
            continue
        card['keywords'] = []
        # Find all keywords in info string. E.g. find 'spawn' in '<keyword=spawn>'
        # Can just use en-US here. It doesn't matter, all regions will return the same result.
        result = re.findall(r'<keyword=([^>]+)>', card['infoRaw']['en-US'])
        for key in result:
            card['keywords'].append(key)

# If a card is not collectible, we don't have the art for it.
def removeInvalidImages(cards):
    for cardId in cards:
        card = cards[cardId]
        for variationId in card['variations']:
            variation = card['variations'][variationId]
            if not variation['collectible']:
                for size in IMAGE_SIZES:
                    del variation['art'][size]

def removeUnreleasedCards(cards):
    # A few cards get falsely flagged as released.

    # Gaunter's 'Higher than 5' token
    cards['200175']['released'] = False
    # Gaunter's 'Lower than 5' token
    cards['200176']['released'] = False


# Add a backslash on the end if it doesn't exist.
if xml_folder[-1] != "/":
    xml_folder = xml_folder + "/"

if not os.path.isdir(xml_folder):
    print(xml_folder + " is not a valid directory")
    exit()

CRAFT_VALUES = {}
CRAFT_VALUES['Common'] = {"standard": 30, "premium": 200, "upgrade": 100}
CRAFT_VALUES['Rare'] = {"standard": 80, "premium": 400, "upgrade": 200}
CRAFT_VALUES['Epic'] = {"standard": 200, "premium": 800, "upgrade": 300}
CRAFT_VALUES['Legendary'] = {"standard": 800, "premium": 1600, "upgrade": 400}

MILL_VALUES = {}
MILL_VALUES['Common'] = {"standard": 10, "premium": 10, "upgrade": 20}
MILL_VALUES['Rare'] = {"standard": 20, "premium": 20, "upgrade": 50}
MILL_VALUES['Epic'] = {"standard": 50, "premium": 50, "upgrade": 80}
MILL_VALUES['Legendary'] = {"standard": 200, "premium": 200, "upgrade": 120}

CATEGORIES = {
    "Aedirn": "Aedirn",
    "Alchemy": "Alchemy",
    "Ambush": "Ambush",
    "An_Craite": "An Craite",
    "Banish_In_Graveyard": "Doomed",
    "Bear": "Bear",
    "Beast": "Beast",
    "Blitz": "Blitz",
    "Blue_Stripes": "Blue Stripes",
    "Breedable": "Breedable",
    "Brokvar": "Brokvar",
    "Cintra": "Cintra",
    "Construct": "Construct",
    "Cursed_One": "Cursed",
    "Devourer": "Devourer",
    "Dimun": "Dimun",
    "Double_Agent": "Double Agent",
    "Draconid": "Draconid",
    "Dragon": "Dragon",
    "Drummond": "Drummond",
    "Dwarf": "Dwarf",
    "Dyrad": "Dryad",
    "Elf": "Elf",
    "Harpy": "Harpy",
    "Heymaey": "Haymaey",
    "Insectoid": "Insectoid",
    "Kaedwen": "Kaedwen",
    "Leader": "Leader",
    "Mage": "Mage",
    "Medic": "Medic",
    "Necrophage": "Necrophage",
    "Non_Decoyable": "Stubborn",
    "Non_Medicable": "Permadeath",
    "Officer": "Officer",
    "Ogroid": "Ogroid",
    "Organic": "Organic",
    "Potion": "Potion",
    "Redania": "Redania",
    "Regressing": "Regressing",
    "Relict": "Relict",
    "Shapeshifter": "Shapeshifter",
    "Soldier": "Soldier",
    "Special": "Special",
    "Specter": "Specter",
    "Spell": "Spell",
    "Spy": "Agent",
    "Support": "Support",
    "Svalblod": "Svalblod",
    "Tactic": "Tactic",
    "Temeria": "Temeria",
    "Tordarroch": "Tordarroch",
    "Tuirseach": "Tuirseach",
    "Vampire": "Vampire",
    "Vodyanoi": "Vodyanoi",
    "War_Machine": "Machine",
    "Weather": "Weather",
    "Wild_Hunt": "Wild Hunt",
    "Witcher": "Witcher"
}

LOCALES = ["en-US", "de-DE", "es-ES", "es-MX", "fr-FR", "it-IT", "ja-JP", "pl-PL", "pt-BR", "ru-RU", "zh-CN", "zh-TW"]

TEMPLATES = getCardTemplates()
ABILITIES = getAbilityData()
TOOLTIPS = {}
TOOLTIP_DATA = getTooltipData()
CARD_NAMES = {}
FLAVOR_STRINGS = {}

for region in LOCALES:
    TOOLTIPS[region] = getRawTooltips(region)
    CARD_NAMES[region] = getCardNames(region)
    FLAVOR_STRINGS[region] = getFlavorStrings(region)

cardData = createCardJson()

# Requires information about other cards, so needs to be done after we have looked at every card.
evaluateInfoData(cardData)
# We have to do this as well to catch cards like Botchling, that are explicitly named in the Baron's tooltip.
evaluateTokens(cardData)
# After the info text has been evaluated, we can extract the keywords (e.g. deploy).
evaluateKeywords(cardData)
removeInvalidImages(cardData)
removeUnreleasedCards(cardData)
# Save under v0-9-10_2017-09-05.json if the script is ran on 5 September 2017 with patch v0-9-10.
saveJson(PATCH + "_" + datetime.utcnow().strftime("%Y-%m-%d") + ".json", cardData)
